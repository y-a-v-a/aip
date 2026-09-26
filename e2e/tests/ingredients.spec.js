import { test, expect } from '@playwright/test';
import { login, mock } from './helpers.js';

test('an edited ingredient list is what the model receives', async ({ page }) => {
  await mock.reset();
  await login(page);

  await page.getByRole('link', { name: 'Ingredients' }).click();
  const original = await page.getByLabel(/Ingredient list/).inputValue();

  // Bullets and duplicates are cleaned up on save.
  await page.getByLabel(/Ingredient list/).fill('• Duck breast\nParsnip\nparsnip\n\n- Blueberries');
  await page.getByRole('button', { name: 'Save ingredients' }).click();
  await expect(page.getByText('Saved — 3 ingredients.')).toBeVisible();
  await expect(page.getByLabel(/Ingredient list/)).toHaveValue('Duck breast\nParsnip\nBlueberries');

  await page.getByRole('link', { name: /New\srecipe/ }).click();
  await page.getByText('Dinner', { exact: true }).click();
  await page.getByRole('button', { name: 'Generate recipe' }).click();
  await expect(page.locator('.recipe .title')).toBeVisible();

  const [call] = await mock.requests();
  const pantry = call.body.messages[0].content.split('## AVAILABLE INGREDIENTS (the ONLY ones you may use)').pop();
  expect(pantry.match(/^- .+$/gm).sort()).toEqual(['- Blueberries', '- Duck breast', '- Parsnip']);
  // No free-text note, so the app picks the only matching protein as the star.
  expect(call.body.messages[1].content).toContain('build this recipe around Duck breast');

  // Restore the shipped list so later runs/tests see the default pantry.
  await page.getByRole('link', { name: 'Ingredients' }).click();
  await page.getByLabel(/Ingredient list/).fill(original);
  await page.getByRole('button', { name: 'Save ingredients' }).click();
  await expect(page.locator('.ok')).toBeVisible();
});
