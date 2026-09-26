import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';
import { APP_URL, MOCK_URL } from './tests/helpers.js';

const here = dirname(fileURLToPath(import.meta.url));

async function waitFor(url, timeoutMs = 60_000) {
  const until = Date.now() + timeoutMs;
  while (Date.now() < until) {
    try {
      const r = await fetch(url, { redirect: 'manual' });
      if (r.status < 500) return;
    } catch {
      // not up yet
    }
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error(`Timed out waiting for ${url}`);
}

export default async function globalSetup() {
  // Start from scratch every run (fresh data/, fresh seeded user).
  execSync('docker compose down -v --remove-orphans', { cwd: here, stdio: 'inherit' });
  execSync('docker compose up -d --build', { cwd: here, stdio: 'inherit' });
  await waitFor(`${MOCK_URL}/__mock/health`);
  await waitFor(`${APP_URL}/login.php`);
}
