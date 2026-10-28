import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner sees a new order and takes it from paid to done', async ({ page }, testInfo) => {
  test.setTimeout(120_000);
  const handle = await signUpBusiness(page, testInfo, 'orders', undefined, 'growth');
  const port = new URL(String(testInfo.project.use.baseURL)).port;

  // Through the real screens: a product, and EFT switched on.
  await page.goto(`/${handle}/shop/products`);
  await page.getByRole('button', { name: 'Add a product' }).click();
  const product = page.getByRole('form', { name: 'Add a product' });
  await product.getByLabel('Name').fill('Cap');
  await product.getByLabel('Price (R)').fill('120');
  await product.getByLabel('Stock').fill('4');
  await product.getByLabel('Show in the shop').check();
  await product.getByRole('button', { name: 'Save product' }).click();
  await expect(page.getByText('Product saved.')).toBeVisible();
  await page.reload(); // the embedded page data is from the first load; reload to see the new product
  const variant = await page.evaluate(() => {
    const data = JSON.parse(
      document.querySelector('script[data-page="app"]')?.textContent ?? '{}',
    ) as {
      props?: { products?: { variants: { id: string }[] }[] };
    };
    return data.props?.products?.[0]?.variants[0]?.id ?? '';
  });

  await page.goto(`/${handle}/shop/settings`);
  const settings = page.getByRole('form', { name: 'Shop settings' });
  await settings.getByLabel('Collection address').fill('12 Vilakazi St, Soweto');
  await settings.getByLabel('Accept orders paid by EFT or on collection').click();
  await settings.getByLabel('How to pay').fill('FNB, account 6200 0000 000');
  await settings.getByRole('button', { name: 'Save shop settings' }).click();
  await expect(page.getByText('Shop settings saved.')).toBeVisible();

  // A customer orders on the business's own address.
  // (The request helper doesn't share the browser's *.wurk.test mapping: send the business's address as the Host.)
  const placed = await page.request.post(`http://127.0.0.1:${port}/cart/checkout`, {
    data: {
      lines: [{ variant, quantity: 2 }],
      name: 'Sipho Ndlovu',
      phone: '082 555 0101',
      fulfilment: 'collection',
      payment: 'offline',
      consent: '1',
    },
    headers: { Accept: 'application/json', Host: `${handle}.wurk.test:${port}` },
  });
  expect(placed.status()).toBe(201);

  await page.goto(`/${handle}/shop/orders?show=awaiting`);
  await expect(page.getByRole('heading', { level: 1, name: 'Orders' })).toBeVisible();
  await expectAccessible(page);
  await page.getByRole('link', { name: /#1001 · Sipho Ndlovu/ }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Order #1001' })).toBeVisible();
  await expectAccessible(page);

  for (const [button, done] of [
    ['Mark as paid', 'Marked as paid.'],
    ['Mark as packed', 'Marked as packed.'],
    ['Ready for collection', 'Marked ready — the customer has been told.'],
    ['Mark as done', 'Marked as done.'],
  ] as const) {
    await page.getByRole('button', { name: button }).click();
    await expect(page.getByText(done)).toBeVisible();
  }
  await expect(page.getByRole('list', { name: 'What happened' }).getByRole('listitem')).toHaveCount(
    5,
  ); // placed, paid, packed, ready, done
  await expectAccessible(page);
});
