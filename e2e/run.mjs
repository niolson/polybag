import { spawn } from 'child_process';
import { readFileSync, writeFileSync } from 'fs';
import { resolve } from 'path';

const envPath = resolve(import.meta.dirname, '..', '.env');
const envContent = readFileSync(envPath, 'utf-8');
let restored = false;

function parseEnv(content) {
  return content.split(/\r?\n/).reduce((values, line) => {
    const trimmed = line.trim();

    if (!trimmed || trimmed.startsWith('#')) {
      return values;
    }

    const separatorIndex = trimmed.indexOf('=');

    if (separatorIndex === -1) {
      return values;
    }

    const key = trimmed.slice(0, separatorIndex).trim();
    let value = trimmed.slice(separatorIndex + 1).trim();

    if (
      (value.startsWith('"') && value.endsWith('"'))
      || (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }

    values[key] = value;

    return values;
  }, {});
}

function restore() {
  if (restored) {
    return;
  }

  writeFileSync(envPath, envContent);
  restored = true;
  console.error('Restored .env');
}

function runCommand(command, args, options = {}) {
  return new Promise((resolvePromise) => {
    const child = spawn(command, args, {
      stdio: 'inherit',
      ...options,
    });

    child.on('close', (code) => resolvePromise(code ?? 1));
  });
}

// Always restore .env, even on crashes or Ctrl+C
process.on('SIGINT', () => { restore(); process.exit(130); });
process.on('SIGTERM', () => { restore(); process.exit(143); });
process.on('uncaughtException', (e) => { restore(); console.error(e); process.exit(1); });

// Toggle FAKE_CARRIERS=true in .env
const updated = envContent.includes('FAKE_CARRIERS=')
  ? envContent.replace(/^FAKE_CARRIERS=.*$/m, 'FAKE_CARRIERS=true')
  : envContent + '\nFAKE_CARRIERS=true';
writeFileSync(envPath, updated);

console.error('Set FAKE_CARRIERS=true in .env');

const runtimeEnv = {
  ...process.env,
  ...parseEnv(updated),
};

// Run Playwright
const args = process.argv.slice(2);
const isListOnly = args.includes('--list');

if (!isListOnly) {
  console.error('Ensuring Playwright Chromium is installed...');
  const installCode = await runCommand('npx', ['playwright', 'install', 'chromium'], {
    env: runtimeEnv,
  });

  if (installCode !== 0) {
    restore();
    process.exit(installCode);
  }
}

const code = await runCommand('npx', ['playwright', 'test', ...args], {
  env: runtimeEnv,
});

restore();
process.exit(code);
