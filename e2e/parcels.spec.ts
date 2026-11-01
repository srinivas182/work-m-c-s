import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner sets parcel boxes and gives a product its weight and size', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'parcels', undefined, 'growth');

  // Settings → Shipping: the default weight and a box.
  await page.goto(`/${handle}/settings/shipping`);
  const parcels = page.getByRole('region', { name: 'Parcels' });
  await parcels.getByLabel('Default weight per item (kg)').fill('0.3');
  // Desktop and phone (and repeats) share this business: count the boxes already there, and fill the new one.
  const rows = parcels.getByRole('list', { name: 'Boxes' }).getByRole('listitem');
  const before = await rows.count();
  await parcels.getByRole('button', { name: 'Add a box' }).click();
  await expect(rows).toHaveCount(before + 1); // one tap, one box
  const name = `Small ${testInfo.project.name} ${Date.now() % 100000}`;
  const box = rows.nth(before);
  await box.getByLabel('Name').fill(name);
  await box.getByLabel('Length (cm)').fill('20');
  await box.getByLabel('Width (cm)').fill('15');
  await box.getByLabel('Height (cm)').fill('10');
  await box.getByLabel('Up to (kg)').fill('2');
  await parcels.getByRole('button', { name: 'Save parcels' }).click();
  await expect(page.getByText('Parcel settings saved.')).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole('region', { name: 'Parcels' }).getByLabel('Default weight per item (kg)'),
  ).toHaveValue('0.3');
  const saved = page
    .getByRole('region', { name: 'Parcels' })
    .getByRole('list', { name: 'Boxes' })
    .getByRole('textbox', { name: 'Name' });
  await expect(saved.nth(before)).toHaveValue(name);
  await expectAccessible(page);
  // An empty row left behind is ignored when saving.
  await page
    .getByRole('region', { name: 'Parcels' })
    .getByRole('button', { name: 'Add a box' })
    .click();
  await page
    .getByRole('region', { name: 'Parcels' })
    .getByRole('button', { name: 'Save parcels' })
    .click();
  await page.reload();
  await expect(
    page
      .getByRole('region', { name: 'Parcels' })
      .getByRole('list', { name: 'Boxes' })
      .getByRole('listitem'),
  ).toHaveCount(before + 1);

  // A product with its weight and size.
  await page.goto(`/${handle}/shop/products`);
  await page.getByRole('button', { name: 'Add a product' }).click();
  const product = page.getByRole('form', { name: 'Add a product' });
  await product.getByLabel('Name').fill('Beaded bracelet');
  await product.getByLabel('Price (R)').fill('180');
  await product.getByLabel('Weight (kg)').fill('0.05');
  await product.getByLabel('Length (cm)').fill('10');
  await product.getByLabel('Width (cm)').fill('8');
  await product.getByLabel('Height (cm)').fill('2');
  await expectAccessible(page);
  await product.getByRole('button', { name: 'Save product' }).click();
  await expect(page.getByText('Product saved.')).toBeVisible();
  await page
    .getByRole('list', { name: 'Products' })
    .getByRole('button', { name: /Beaded bracelet/ })
    .first()
    .click();
  await expect(
    page.getByRole('form', { name: /Beaded bracelet|Edit/ }).getByLabel('Weight (kg)'),
  ).toHaveValue('0.05');
});
