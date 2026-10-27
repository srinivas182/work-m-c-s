import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner adds a product with sizes and stock, and edits it', async ({ page }, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'shop', undefined, 'growth');
  await page.goto(`/${handle}/shop/products`);
  await expect(page.getByRole('heading', { level: 1, name: 'Products' })).toBeVisible();
  await expectAccessible(page);

  await page.getByRole('button', { name: 'Add a product' }).click();
  const form = page.getByRole('form', { name: 'Add a product' });
  await form.getByLabel('Name').fill('Kasi T-shirt');
  await form.getByRole('button', { name: 'Add an option' }).click(); // "Size"
  const sizes = form.getByLabel('Size', { exact: true });
  await sizes.nth(0).fill('M');
  await form.getByLabel('Price (R)').nth(0).fill('250');
  await form.getByLabel('Stock').nth(0).fill('5');
  await form.getByRole('button', { name: 'Add a variant' }).click();
  await sizes.nth(1).fill('L');
  await form.getByLabel('Price (R)').nth(1).fill('250');
  await form.getByLabel('Stock').nth(1).fill('3');
  await form.getByLabel('Show in the shop').check();
  await expectAccessible(page);
  await form.getByRole('button', { name: 'Save product' }).click();

  await expect(page.getByText('Product saved.')).toBeVisible();
  const list = page.getByRole('list', { name: 'Products' });
  await expect(list.getByText('R250 · 2 variants · 8 in stock')).toBeVisible();
  await expect(list.getByText('In the shop')).toBeVisible();

  await page.getByRole('button', { name: 'Edit Kasi T-shirt' }).click();
  const edit = page.getByRole('form', { name: 'Edit Kasi T-shirt' });
  await edit.getByLabel('Stock').nth(1).fill('0');
  await edit.getByRole('button', { name: 'Save product' }).click();
  await expect(list.getByText('R250 · 2 variants · 5 in stock')).toBeVisible();
  await expectAccessible(page);
});
