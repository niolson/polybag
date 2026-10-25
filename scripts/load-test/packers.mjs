#!/usr/bin/env node
// Simulated packers for capacity-audit/01: each one loops open order -> scan box
// and items -> ship -> pick a rate -> buy, in its own browser session, and the
// script reports how long each server round trip took.
//
// Seed first (inside the app container, with FAKE_CARRIERS=true):
//   php artisan app:seed-load-test --packers=20 --shipments=50 > plan.json
// Then from the repository root:
//   node scripts/load-test/packers.mjs --base-url=http://localhost --plan=plan.json --packers=5
//
// Options:
//   --base-url        The instance to load (required)
//   --plan            JSON printed by app:seed-load-test (required)
//   --packers         How many of the plan's packers run at once (default: all)
//   --duration        Seconds to run before stopping (default: 120)
//   --think-ms        Pause between a packer's scans (default: 1500)
//   --out             Write raw timings and the summary to this JSON file
//   --headed          Show the browsers
//
// Item and box scans are handled in the browser and never reach the server;
// what a packer waits on is the save after each scan (saveDraft), opening an
// order, the Ship page load (which quotes rates), and the purchase.

import { readFileSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';

const args = Object.fromEntries(
    process.argv.slice(2).map((arg) => {
        const [key, ...rest] = arg.replace(/^--/, '').split('=');
        return [key, rest.length ? rest.join('=') : true];
    }),
);

if (!args['base-url'] || !args.plan) {
    console.error('Usage: node scripts/load-test/packers.mjs --base-url=URL --plan=plan.json [--packers=N] [--duration=120]');
    process.exit(1);
}

const baseUrl = String(args['base-url']).replace(/\/$/, '');
const plan = JSON.parse(readFileSync(String(args.plan), 'utf8'));
const packers = plan.packers.slice(0, Number(args.packers ?? plan.packers.length));
const durationMs = Number(args.duration ?? 120) * 1000;
const thinkMs = Number(args['think-ms'] ?? 1500);
let deadline = Infinity;

/** @type {Record<string, number[]>} */
const timings = {};
const counts = { purchased: 0, failed: 0, afterWindow: 0, errors: [] };

// Only what finishes inside the window counts. Purchases in flight at the
// deadline still run to completion, but as packers stop the load falls, so
// their timings would flatter the tail and inflate labels per minute.
const inWindow = () => Date.now() <= deadline;
const record = (name, ms) => inWindow() && (timings[name] ??= []).push(ms);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const jitter = (ms) => ms * (0.75 + Math.random() * 0.5);

// What a Livewire round trip did, from its payload: the methods it called,
// else the properties it updated. Same naming as the request-timing log.
function livewireLabel(postData) {
    try {
        const body = JSON.parse(postData ?? '{}');
        const parts = (body.components ?? []).flatMap((component) => {
            const calls = (component.calls ?? []).map((call) =>
                ['mountAction', 'callMountedAction'].includes(call.method) && typeof call.params?.[0] === 'string'
                    ? `${call.method}:${call.params[0]}`
                    : call.method,
            );
            return calls.length ? calls : Object.keys(component.updates ?? {}).map((key) => `update:${key}`);
        });
        return `livewire ${parts.join(',') || 'sync'}`;
    } catch {
        return 'livewire unknown';
    }
}

function pageLabel(url) {
    const path = new URL(url).pathname;
    if (/^\/pack\/\d+/.test(path)) return 'GET /pack/{shipment}';
    if (/^\/ship\/\d+/.test(path)) return 'GET /ship/{package} (rates)';
    return `GET ${path}`;
}

function watchRoundTrips(page) {
    const started = new WeakMap();

    page.on('request', (request) => started.set(request, Date.now()));
    page.on('requestfinished', (request) => {
        const start = started.get(request);
        if (start === undefined) return;

        if (request.method() === 'POST' && /\/livewire[^/]*\/update/.test(request.url())) {
            record(livewireLabel(request.postData()), Date.now() - start);
        } else if (request.resourceType() === 'document') {
            record(pageLabel(request.url()), Date.now() - start);
        }
    });
}

async function logIn(context, username) {
    const page = await context.newPage();

    for (let attempt = 1; attempt <= 5; attempt++) {
        await page.goto(`${baseUrl}/login`);
        await page.locator('input[type="text"], input[type="email"]').first().fill(username);
        await page.locator('input[type="password"]').fill(plan.password);
        await page.getByRole('button', { name: /sign in/i }).click();

        try {
            await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
            await page.close();
            return;
        } catch {
            // The login form allows a few attempts a minute from one address.
            console.log(`${username}: login not accepted (attempt ${attempt}), waiting a minute`);
            await sleep(61000);
        }
    }

    throw new Error(`${username} could not log in`);
}

// The Alpine state of the Pack page: box codes and the items to pack.
const packState = (page) =>
    page.evaluate(() => {
        const data = [...document.querySelectorAll('[x-data]')]
            .map((el) => window.Alpine.$data(el))
            .find((candidate) => candidate && 'packingItems' in candidate);

        return {
            boxCodes: Object.keys(data.boxSizes),
            items: data.packingItems.map((item) => ({ barcode: item.barcode || item.sku, remaining: item.quantity - item.packed })),
        };
    });

async function scan(page, value) {
    const input = page.locator('input[x-ref="scanInput"]');
    await input.fill(value);
    await input.press('Enter');
}

const think = () => sleep(jitter(thinkMs));

async function packOne(page, reference) {
    await page.goto(`${baseUrl}/pack`);
    const started = Date.now();
    await scan(page, reference);
    await page.waitForURL(/\/pack\/\d+/, { timeout: 60000 });
    record('scan order -> Pack page shown', Date.now() - started);
    await think();

    const { boxCodes, items } = await packState(page);
    await scan(page, boxCodes[0]);
    await think();

    for (const item of items) {
        for (let unit = 0; unit < item.remaining; unit++) {
            await scan(page, item.barcode);
            await think();
        }
    }

    // A scale reading lands in the weight field the same way.
    await page.locator('input[x-model="weight"]').fill('1.25');
    await think();

    const shipStarted = Date.now();
    await page.keyboard.press('F12');
    await page.waitForURL(/\/ship\/\d+/, { timeout: 120000 });
    await page.getByRole('button', { name: 'Buy & Print Label' }).waitFor({ timeout: 120000 });
    record('ship -> rates shown', Date.now() - shipStarted);

    const buy = page.getByRole('button', { name: 'Buy & Print Label' });
    if (await buy.isDisabled()) {
        await page.locator('input[type="radio"][value="0"]').first().check();
    }

    await think();
    const buyStarted = Date.now();
    await buy.click();
    await page.getByText('Label Bought').first().waitFor({ timeout: 120000 });
    record('buy -> label bought', Date.now() - buyStarted);
}

async function runPacker(context, packer) {
    const page = await context.newPage();
    watchRoundTrips(page);

    for (const reference of packer.shipments) {
        if (Date.now() > deadline) break;

        try {
            await packOne(page, reference);
            counts[inWindow() ? 'purchased' : 'afterWindow']++;
        } catch (error) {
            if (inWindow()) {
                counts.failed++;
                counts.errors.push(`${packer.username} ${reference}: ${error.message.split('\n')[0]}`);
            } else {
                counts.afterWindow++;
            }
        }
    }

    await context.close();
}

const percentile = (sorted, p) => sorted[Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length))];

function summarize() {
    return Object.entries(timings)
        .map(([name, values]) => {
            const sorted = [...values].sort((a, b) => a - b);
            return { name, count: sorted.length, p50: percentile(sorted, 50), p95: percentile(sorted, 95), max: sorted.at(-1) };
        })
        .sort((a, b) => a.name.localeCompare(b.name));
}

const browser = await chromium.launch({ headless: !args.headed });

// Log in one at a time, so the login form's rate limit is met head on rather
// than by every packer at once; then all of them pack together.
const contexts = [];
for (const packer of packers) {
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    await logIn(context, packer.username);
    contexts.push(context);
}

console.log(`${packers.length} packers against ${baseUrl} for ${durationMs / 1000}s`);
deadline = Date.now() + durationMs;
await Promise.all(packers.map((packer, i) => runPacker(contexts[i], packer)));
await browser.close();

const summary = summarize();
console.table(summary);
const labelsPerMinute = counts.purchased / (durationMs / 60000);
console.log(`Labels bought in ${durationMs / 1000}s: ${counts.purchased} (${labelsPerMinute.toFixed(1)}/min), failed: ${counts.failed}, finished after the window (not counted): ${counts.afterWindow}`);
counts.errors.slice(0, 10).forEach((error) => console.log(`  ${error}`));

if (args.out) {
    writeFileSync(String(args.out), JSON.stringify({ packers: packers.length, thinkMs, durationMs, labelsPerMinute, summary, timings, counts }, null, 2));
}
