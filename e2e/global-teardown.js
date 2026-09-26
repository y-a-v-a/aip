import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

export default async function globalTeardown() {
  // Set E2E_KEEP_STACK=1 to leave containers running for debugging.
  if (process.env.E2E_KEEP_STACK === '1') return;
  execSync('docker compose down -v --remove-orphans', { cwd: here, stdio: 'inherit' });
}
