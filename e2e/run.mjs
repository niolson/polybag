import { spawn } from 'child_process';
import { readFileSync, writeFileSync } from 'fs';
import { resolve } from 'path';

const envPath = resolve(import.meta.dirname, '..', '.env');
const envContent = readFileSync(envPath, 'utf-8');

function restore() {
  writeFileSync(envPath, envContent);
  console.error('Restored .env');
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

// Run Playwright
const args = process.argv.slice(2);
const pw = spawn('npx', ['playwright', 'test', ...args], {
  stdio: 'inherit',
  shell: true,
});

const code = await new Promise((r) => pw.on('close', r));

restore();
process.exit(code);
