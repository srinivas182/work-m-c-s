import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('the owner records an in-store sale for a new customer, refunds part of it and sets the approval limit', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'sales', undefined, 'growth');
  await page.goto(`/${handle}/sales`);
  await expect(page.getByRole('heading', { level: 1, name: 'Record sale' })).toBeVisible();
  await expectAccessible(page);

  const form = page.getByRole('form', { name: 'Record a sale' });
  const phone = `083 ${String(Date.now()).slice(-7, -4)} ${String(Date.now()).slice(-4)}`; // a new customer each run
  const name = `Walk-in ${testInfo.project.name}`;
  await form.getByLabel('Customer’s phone').fill(phone);
  await form.getByRole('button', { name: 'Find' }).click();
  await expect(form.getByText('New customer — enter their name.')).toBeVisible();
  await form.getByLabel('Name').fill(name);
  await form.getByLabel('Amount paid (R)').fill('120');
  await form.getByLabel('Cash').check();
  await form.getByRole('button', { name: 'Record sale' }).click();
  await expect(page.getByText('Sale recorded.')).toBeVisible();

  const mine = page
    .getByRole('list', { name: 'Recent sales' })
    .getByRole('listitem')
    .filter({ hasText: name });
  await expect(mine).toContainText('R120'); // formatted like everywhere in WURK (lib/money)
  await mine.getByRole('button', { name: 'Refund…' }).click();
  const refund = mine.getByRole('form', { name: `Refund ${name}’s sale` });
  await refund.getByLabel('Refund (R)').fill('20');
  await refund.getByRole('button', { name: 'Refund' }).click();
  await expect(
    page
      .getByRole('list', { name: 'Recent sales' })
      .getByRole('listitem')
      .filter({ hasText: name }),
  ).toContainText('Refunded R20');

  const limit = page.getByRole('form', { name: 'Approval limit' });
  await limit.getByLabel(/Sales over this amount need a manager/).fill('1000');
  await limit.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Sales over R1,000.00 now need approval.')).toBeVisible();
  await expectAccessible(page);
});
