import { expect } from '@playwright/test';

export const APP_URL = `http://localhost:${process.env.E2E_APP_PORT || 8081}`;
export const MOCK_URL = `http://localhost:${process.env.E2E_MOCK_PORT || 4010}`;

// Must match DEV_PHONE / DEV_PIN in e2e/docker-compose.yml. The seeded user is
// an admin (docker-entrypoint.sh passes --admin).
export const USER = { phone: '+31600000001', pin: '246810' };

export async function login(page, identifier = USER.phone, pin = USER.pin) {
  await page.goto('/login.php');
  await page.getByLabel('Phone number or email').fill(identifier);
  await page.getByLabel('PIN').fill(pin);
  await page.getByRole('button', { name: 'Log in' }).click();
  await expect(page.getByRole('button', { name: 'Generate recipe' })).toBeVisible();
}

/** Log in as the seeded admin and pass the admin PIN re-check. */
export async function openAdmin(page) {
  await login(page);
  await page.goto('/admin.php');
  await page.getByLabel('Your PIN').fill(USER.pin);
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('button', { name: 'Add & create login link' })).toBeVisible();
}

/**
 * Add a person on the admin page (which must already be open + confirmed) and
 * return the one-time login link it shows.
 */
export async function addPerson(page, { identifier, name = '', pin = '' }) {
  await page.getByLabel(/Add a person/).fill(identifier);
  if (name) await page.getByLabel(/^Name/).fill(name);
  if (pin) await page.getByLabel(/^PIN/).fill(pin);
  await page.getByRole('button', { name: 'Add & create login link' }).click();
  return page.locator('#link').inputValue();
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
