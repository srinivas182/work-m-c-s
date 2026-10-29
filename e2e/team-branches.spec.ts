import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner adds a branch and invites a branch manager', async ({ page }, testInfo) => {
  test.setTimeout(120_000);
  const handle = await signUpBusiness(page, testInfo, 'branches', undefined, 'pro');

  await page.goto(`/${handle}/settings/branches`);
  await expect(page.getByRole('heading', { level: 1, name: 'Branches' })).toBeVisible();
  await expect(page.getByText(/^1 of 5 branches open/)).toBeVisible();
  const add = page.getByRole('form', { name: 'Add a branch' });
  await add.getByLabel('Name').fill('Sandton');
  await add.getByLabel('Address').fill('12 Rivonia Rd, Sandton');
  await add.getByRole('button', { name: 'Add branch' }).click();
  await expect(page.getByText('Branch added.')).toBeVisible();
  await expect(page.getByRole('list', { name: 'Branches' })).toContainText(
    '12 Rivonia Rd, Sandton',
  );
  await expectAccessible(page);

  // Bookings by branch: hours at the new branch.
  await page.goto(`/${handle}/bookings/settings`);
  await page.getByLabel('Hours at').selectOption({ label: 'Sandton' });
  const hours = page.getByRole('region', { name: /’s hours$/ }).first();
  await expect(hours).toBeVisible();
  await expectAccessible(page);

  // Shop by branch: stock at each branch; the list shows the total.
  await page.goto(`/${handle}/shop/products`);
  await page.getByRole('button', { name: 'Add a product' }).click();
  const product = page.getByRole('form', { name: 'Add a product' });
  await product.getByLabel('Name').fill('Cap');
  await product.getByLabel('Price (R)').fill('120');
  await product
    .getByLabel(/^Stock at Main|^Stock at /)
    .first()
    .fill('2');
  await product.getByLabel('Stock at Sandton').fill('3');
  await product.getByLabel('Price at Sandton (R, optional)').fill('130');
  await product.getByLabel('Show in the shop').check();
  await product.getByRole('button', { name: 'Save product' }).click();
  await expect(page.getByText('Product saved.')).toBeVisible();
  await expect(page.getByText(/5 in stock/)).toBeVisible();
  await expectAccessible(page);

  // Payments by branch: Sandton gets its own bank details.
  await page.goto(`/${handle}/settings/payments`);
  await page.getByLabel('Accounts for').selectOption({ label: 'Sandton' });
  await expect(
    page.getByText('A method this branch doesn’t have its own account for uses the business’s.'),
  ).toBeVisible();
  await page.getByRole('button', { name: 'Add bank details' }).click();
  const eft = page.getByRole('form', { name: 'EFT / bank transfer live keys' });
  await eft
    .getByLabel('How to pay (bank, account number, branch code)')
    .fill('Capitec, Sandton account 1234567890');
  await eft.getByRole('button', { name: 'Save live details' }).click();
  await expect(page.getByText('EFT / bank transfer live keys saved.')).toBeVisible();
  await expectAccessible(page);

  await page.goto(`/${handle}/settings/team`);
  await expect(page.getByRole('heading', { level: 1, name: 'Team' })).toBeVisible();
  await expect(page.getByRole('list', { name: 'Team members' }).getByText('You')).toBeVisible();
  const email = `manager-${testInfo.project.name}@example.com`;
  const invite = page.getByRole('form', { name: 'Invite someone' });
  await invite.getByLabel('Email').fill(email);
  await invite.getByLabel('Role').selectOption({ label: 'Branch manager' });
  await invite.getByRole('button', { name: 'Send invitation' }).click();
  await expect(page.getByText(`Invitation sent to ${email}.`)).toBeVisible();
  await expect(page.getByRole('list', { name: 'Invitations waiting' })).toContainText(email);
  await expectAccessible(page);
  await page.getByRole('button', { name: `Cancel the invitation to ${email}` }).click();
  await expect(page.getByText('Invitation cancelled.')).toBeVisible();
});
