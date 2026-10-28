import { expect, test } from '@playwright/test';
import { expectAccessible } from './support';

test('a shopper adds a dress to the cart and places an order paid by EFT', async ({
  page,
}, testInfo) => {
  test.setTimeout(60_000);
  const port = new URL(String(testInfo.project.use.baseURL)).port;
  const site = `http://demo-retail-boutique.wurk.test:${port}`;
  await page.goto(`${site}/shop/linen-summer-dress`);
  await page.getByLabel('Choose').selectOption({ label: 'M — R899.00' });
  await page.getByRole('button', { name: 'Add to cart' }).click();
  await expect(page.getByRole('status')).toContainText('Added to your cart (1 item)');
  await expectAccessible(page);
  await page.getByRole('link', { name: 'View cart and check out' }).click();

  await expect(page.getByRole('heading', { level: 1, name: 'Your cart' })).toBeVisible();
  const items = page.getByRole('list', { name: 'Items in your cart' });
  await expect(items.getByRole('link', { name: 'Linen summer dress (M)' })).toBeVisible();
  await page.getByLabel('Quantity').fill('2');
  await page.getByLabel('Quantity').dispatchEvent('change');
  await expect(page.getByText('Total R1798.00 (incl. VAT)')).toBeVisible();
  await page.getByLabel(/^Delivery — R60\.00/).check();
  await expect(page.getByText('Total R1858.00 (incl. VAT, delivery R60.00)')).toBeVisible();
  await page.getByLabel(/^Collect/).check();
  await page.getByLabel('Your name').fill('Sipho Ndlovu');
  await page.getByLabel('Phone').fill('082 555 0101');
  await page.getByLabel('I agree to be contacted about my order.').check();
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Place order' }).click();

  await expect(page.getByRole('heading', { level: 1, name: /^Order #\d+$/ })).toBeVisible();
  await expect(page.getByText('Waiting for payment')).toBeVisible();
  await expect(page.getByRole('region', { name: 'How to pay' })).toContainText('Demo Bank');
  await expect(page.getByText('2 × Linen summer dress (M)')).toBeVisible();
  await expectAccessible(page);
});
