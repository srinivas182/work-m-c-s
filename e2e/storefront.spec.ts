import { expect, test } from '@playwright/test';
import { expectAccessible } from './support';

test('a shopper browses the grid and opens a product page', async ({ page }, testInfo) => {
  test.setTimeout(60_000);
  const port = new URL(String(testInfo.project.use.baseURL)).port;
  await page.goto(`http://demo-retail-boutique.wurk.test:${port}/`); // shop:demo
  const grid = page.locator('[data-block="ProductGrid"]');
  await expect(grid.getByRole('heading', { name: 'New in' })).toBeVisible();
  await expect(grid.getByText('Sold out')).toBeVisible();
  await expectAccessible(page);

  await grid.getByRole('link', { name: /Linen summer dress/ }).click();
  await expect(page).toHaveURL(/\/shop\/linen-summer-dress$/);
  await expect(page.getByRole('heading', { level: 1, name: 'Linen summer dress' })).toBeVisible();
  const table = page.getByRole('table', { name: 'Options and prices' });
  await expect(table.getByRole('row', { name: /^L .* Sold out$/ })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Add to cart' })).toBeVisible(); // the demo shop takes orders (otherwise: “Ask about this product”)
  await expectAccessible(page);

  await page.goto(`http://demo-retail-boutique.wurk.test:${port}/shop/no-such-product`);
  await expect(page.getByRole('heading', { name: 'Product not found' })).toBeVisible();
});
