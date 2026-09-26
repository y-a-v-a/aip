import { expect } from '@playwright/test';

export const APP_URL = `http://localhost:${process.env.E2E_APP_PORT || 8081}`;
export const MOCK_URL = `http://localhost:${process.env.E2E_MOCK_PORT || 4010}`;

// Must match DEV_PHONE / DEV_PIN in e2e/docker-compose.yml.
export const USER = { phone: '+31600000001', pin: '246810' };

export async function login(page) {
  await page.goto('/login.php');
  await page.getByLabel('Phone number').fill(USER.phone);
  await page.getByLabel('PIN').fill(USER.pin);
  await page.getByRole('button', { name: 'Log in' }).click();
  await expect(page.getByRole('button', { name: 'Generate recipe' })).toBeVisible();
}

export const mock = {
  async reset() {
    await fetch(`${MOCK_URL}/__mock/reset`, { method: 'POST' });
  },
  /** Queue one response: {content, finish_reason?} or raw {status, body}. */
  async enqueue(item) {
    await fetch(`${MOCK_URL}/__mock/enqueue`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(item),
    });
  },
  async requests() {
    return (await fetch(`${MOCK_URL}/__mock/requests`)).json();
  },
};
