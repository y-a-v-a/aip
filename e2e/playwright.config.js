import { defineConfig, devices } from '@playwright/test';
import { APP_URL } from './tests/helpers.js';

export default defineConfig({
  testDir: './tests',
  // One shared app + mock: run serially so queued mock responses and saved
  // recipes can't leak between tests.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: [['list']],
  globalSetup: './global-setup.js',
  globalTeardown: './global-teardown.js',
  use: {
    baseURL: APP_URL,
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
