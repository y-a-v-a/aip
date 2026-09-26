import { test, expect } from '@playwright/test';
import { login, USER } from './helpers.js';

test('unauthenticated visitors are sent to the login page', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveURL(/\/login\.php$/);
  await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
});

test('a wrong PIN is rejected', async ({ page }) => {
  await page.goto('/login.php');
  await page.getByLabel('Phone number').fill(USER.phone);
  await page.getByLabel('PIN').fill('000000');
  await page.getByRole('button', { name: 'Log in' }).click();
  await expect(page.getByText('Invalid phone number or PIN.')).toBeVisible();
});

test('a valid login reaches the generator and can log out', async ({ page }) => {
  await login(page);
  await page.getByRole('link', { name: /Log\sout/ }).click();
  await page.goto('/');
  await expect(page).toHaveURL(/\/login\.php$/);
});

test('sensitive files are not served', async ({ request }) => {
  for (const path of ['/data/users.json', '/lib/openrouter.php', '/ingredients.txt', '/config.php']) {
    const r = await request.get(path);
    expect(r.status(), path).toBe(404);
    expect(await r.text(), path).not.toContain('<?php');
  }
});
