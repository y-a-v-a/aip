import { test, expect } from '@playwright/test';
import { login, openAdmin, addPerson, USER } from './helpers.js';

// Each test uses its own identifiers: the stack (and users.json) is shared by
// the whole run.

const generator = (page) => page.getByRole('button', { name: 'Generate recipe' });

test('admin page sends anonymous visitors to the login page', async ({ page }) => {
  await page.goto('/admin.php');
  await expect(page).toHaveURL(/\/login\.php$/);
});

test('admin page needs the admin PIN again, and rejects a wrong one', async ({ page }) => {
  await login(page);
  await expect(page.getByRole('link', { name: 'Admin' })).toBeVisible();
  await page.goto('/admin.php');
  await page.getByLabel('Your PIN').fill('999999');
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByText('Wrong PIN.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Add & create login link' })).toHaveCount(0);

  await page.getByLabel('Your PIN').fill(USER.pin);
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('button', { name: 'Add & create login link' })).toBeVisible();
});

test('invite by phone: the link signs the person in and keeps them signed in', async ({ page, browser }) => {
  await openAdmin(page);
  const link = await addPerson(page, { identifier: '+31 6-1111 2222', name: 'Anna' });
  expect(link).toMatch(/\/login\.php\?t=[0-9a-f]{48}$/);
  await expect(page.getByText('Added +31611112222.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Send via WhatsApp' }))
    .toHaveAttribute('href', /^https:\/\/wa\.me\/31611112222\?text=.*login\.php/);
  await expect(page.locator('li[data-user="+31611112222"]')).toContainText('link pending');

  const ctx = await browser.newContext();
  const guest = await ctx.newPage();

  // Merely opening the link (what a chat-app link preview does) must not use it up.
  await guest.goto(link);
  await guest.goto(link);
  await expect(guest.getByText('Welcome, Anna!')).toBeVisible();
  await guest.getByRole('button', { name: 'Sign in' }).click();
  await expect(generator(guest)).toBeVisible();
  // Non-admins don't see the admin link.
  await expect(guest.getByRole('link', { name: 'Admin' })).toHaveCount(0);

  // The PHP session ends (browser restart); the device cookie signs them back in.
  const cookies = await ctx.cookies();
  expect(cookies.find((c) => c.name === 'aip_device')?.httpOnly).toBe(true);
  await ctx.clearCookies();
  await ctx.addCookies(cookies.filter((c) => c.name === 'aip_device'));
  await guest.goto('/');
  await expect(generator(guest)).toBeVisible();

  // The link is single-use.
  const other = await browser.newPage();
  await other.goto(link);
  await expect(other.getByText('invalid, already used, or expired')).toBeVisible();
  await expect(other.getByRole('button', { name: 'Sign in' })).toHaveCount(0);
  await other.close();

  await page.reload();
  await expect(page.locator('li[data-user="+31611112222"]')).toContainText('1 device');
  await ctx.close();
});

test('a non-admin gets a 404 for the admin page', async ({ page, browser }) => {
  await openAdmin(page);
  const link = await addPerson(page, { identifier: '+31622223333' });

  const guest = await browser.newPage();
  await guest.goto(link);
  await guest.getByRole('button', { name: 'Sign in' }).click();
  await expect(generator(guest)).toBeVisible();
  const r = await guest.goto('/admin.php');
  expect(r.status()).toBe(404);
  await guest.close();
});

test('invite by email with a PIN: email + PIN login works too', async ({ page, browser }) => {
  await openAdmin(page);
  const link = await addPerson(page, { identifier: 'Bea@Example.com', name: 'Bea', pin: '135790' });
  expect(link).toContain('/login.php?t=');
  await expect(page.getByRole('link', { name: 'Send by email' }))
    .toHaveAttribute('href', /^mailto:bea@example\.com\?subject=/);
  await expect(page.locator('li[data-user="bea@example.com"]')).toContainText('PIN');

  const guest = await browser.newPage();
  await login(guest, 'BEA@example.com', '135790');
  await guest.close();
});

test('the add form rejects bad input and duplicates', async ({ page }) => {
  await openAdmin(page);
  await page.getByLabel(/Add a person/).fill('not a phone');
  await page.getByRole('button', { name: 'Add & create login link' }).click();
  await expect(page.getByText('Enter a valid phone number')).toBeVisible();

  await page.getByLabel(/Add a person/).fill('0612345678');
  await page.getByRole('button', { name: 'Add & create login link' }).click();
  await expect(page.getByText('international format')).toBeVisible();

  await page.getByLabel(/Add a person/).fill('cor@example.com');
  await page.getByLabel(/^PIN/).fill('123');
  await page.getByRole('button', { name: 'Add & create login link' }).click();
  await expect(page.getByText('A PIN must be at least 6 characters')).toBeVisible();

  await addPerson(page, { identifier: 'cor@example.com' });
  await page.getByLabel(/Add a person/).fill('COR@example.com');
  await page.getByRole('button', { name: 'Add & create login link' }).click();
  await expect(page.getByText('cor@example.com already has access')).toBeVisible();
});

test('a new login link replaces the old one', async ({ page, browser }) => {
  await openAdmin(page);
  const first = await addPerson(page, { identifier: 'dirk@example.com' });
  await page.locator('li[data-user="dirk@example.com"]').getByRole('button', { name: 'New login link' }).click();
  const second = await page.locator('#link').inputValue();
  expect(second).not.toBe(first);

  const guest = await browser.newPage();
  await guest.goto(first);
  await expect(guest.getByText('invalid, already used, or expired')).toBeVisible();
  await guest.goto(second);
  await guest.getByRole('button', { name: 'Sign in' }).click();
  await expect(generator(guest)).toBeVisible();
  await guest.close();
});

test('sign out everywhere and remove cut off access immediately', async ({ page, browser }) => {
  page.on('dialog', (d) => d.accept());
  await openAdmin(page);
  const link = await addPerson(page, { identifier: 'eva@example.com' });

  const ctx = await browser.newContext();
  const guest = await ctx.newPage();
  await guest.goto(link);
  await guest.getByRole('button', { name: 'Sign in' }).click();
  await expect(generator(guest)).toBeVisible();

  // Sign out everywhere: both the live session and the device cookie stop working.
  await page.locator('li[data-user="eva@example.com"]').getByRole('button', { name: 'Sign out everywhere' }).click();
  await expect(page.getByText('Signed eva@example.com out on all devices.')).toBeVisible();
  await guest.goto('/');
  await expect(guest).toHaveURL(/\/login\.php$/);

  // Back in with a fresh link, then removed: out again, and the link list drops them.
  await page.locator('li[data-user="eva@example.com"]').getByRole('button', { name: 'New login link' }).click();
  const again = await page.locator('#link').inputValue();
  await guest.goto(again);
  await guest.getByRole('button', { name: 'Sign in' }).click();
  await expect(generator(guest)).toBeVisible();

  await page.locator('li[data-user="eva@example.com"]').getByRole('button', { name: 'Remove' }).click();
  await expect(page.getByText('Removed eva@example.com.')).toBeVisible();
  await expect(page.locator('li[data-user="eva@example.com"]')).toHaveCount(0);
  await guest.goto('/');
  await expect(guest).toHaveURL(/\/login\.php$/);
  await ctx.close();
});

test('the admin cannot remove themselves', async ({ page }) => {
  await openAdmin(page);
  const me = page.locator(`li[data-user="${USER.phone}"]`);
  await expect(me).toContainText('admin');
  await expect(me.getByRole('button', { name: 'Remove' })).toHaveCount(0);
});

test('admin actions require a valid CSRF token', async ({ page }) => {
  await openAdmin(page);
  const r = await page.request.post('/admin.php', {
    form: { action: 'add', identifier: 'mallory@example.com', csrf: 'nope' },
  });
  expect(await r.text()).toContain('Session expired');
  await page.reload();
  await expect(page.locator('li[data-user="mallory@example.com"]')).toHaveCount(0);
});

test('logging out forgets this device', async ({ browser }) => {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await login(page); // "Keep me signed in" is on by default
  const device = (await ctx.cookies()).find((c) => c.name === 'aip_device');
  expect(device).toBeTruthy();

  await page.getByRole('link', { name: /Log\sout/ }).click();
  await ctx.clearCookies();
  await ctx.addCookies([device]); // replaying the old device cookie must not work
  await page.goto('/');
  await expect(page).toHaveURL(/\/login\.php$/);
  await ctx.close();
});
