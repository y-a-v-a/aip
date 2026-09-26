import { test, expect } from '@playwright/test';
import { login, mock } from './helpers.js';

test.beforeEach(async ({ page }) => {
  await mock.reset();
  await login(page);
});

test('generates a recipe via OpenRouter, shows it and saves it', async ({ page }) => {
  await mock.enqueue({ content: 'Mock Salmon Traybake\n\nIngredients:\n- 1 salmon fillet\n\nMethod:\n1. Bake.' });

  await page.getByText('Dinner', { exact: true }).click();
  await page.getByLabel('Anything specific? (optional)').fill('something quick');
  await page.getByRole('button', { name: 'Generate recipe' }).click();

  await expect(page.locator('.recipe .title')).toHaveText('Mock Salmon Traybake');
  await expect(page.locator('.recipe')).toContainText('1. Bake.');

  // The app spoke the OpenRouter chat-completions protocol.
  const [call] = await mock.requests();
  expect(call.headers.authorization).toBe('Bearer sk-or-e2e-test-key');
  expect(call.body.model).toMatch(/^[\w-]+\/[\w.-]+$/); // OpenRouter "vendor/model" ID
  expect(call.body.messages.map((m) => m.role)).toEqual(['system', 'user']);
  expect(call.body.messages[0].content).toContain('AVAILABLE INGREDIENTS');
  expect(call.body.messages[1].content).toContain('Create a dinner recipe.');
  expect(call.body.messages[1].content).toContain('something quick');

  // It is persisted and browsable.
  await page.getByRole('link', { name: 'view all saved recipes' }).click();
  await page.getByRole('link', { name: /Mock Salmon Traybake/ }).click();
  await expect(page.locator('.recipe .title')).toHaveText('Mock Salmon Traybake');
  await expect(page.getByText(/^Dinner/)).toBeVisible();
});

test('shows the OpenRouter error message on an HTTP error', async ({ page }) => {
  await mock.enqueue({ status: 402, body: { error: { code: 402, message: 'Insufficient credits' } } });

  await page.getByText('Lunch', { exact: true }).click();
  await page.getByRole('button', { name: 'Generate recipe' }).click();

  await expect(page.locator('.err')).toHaveText('OpenRouter API error: Insufficient credits');
  await expect(page.locator('.recipe')).toHaveCount(0);
});

test('treats an error object in a 200 response as a failure', async ({ page }) => {
  await mock.enqueue({ status: 200, body: { error: { code: 403, message: 'Flagged by moderation' } } });

  await page.getByText('Lunch', { exact: true }).click();
  await page.getByRole('button', { name: 'Generate recipe' }).click();

  await expect(page.locator('.err')).toHaveText('OpenRouter API error: Flagged by moderation');
});

test('reports a content-filter refusal', async ({ page }) => {
  await mock.enqueue({ content: '', finish_reason: 'content_filter' });

  await page.getByText('Dessert', { exact: true }).click();
  await page.getByRole('button', { name: 'Generate recipe' }).click();

  await expect(page.locator('.err')).toHaveText('The model declined to generate this recipe.');
});
