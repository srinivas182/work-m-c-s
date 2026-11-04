import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner sets up the referral programme within the package’s limits', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'referrals', undefined, 'growth');
  await page.goto(`/${handle}/settings/referrals`);
  await expect(page.getByRole('heading', { level: 1, name: 'Referrals' })).toBeVisible();
  await expectAccessible(page);

  const form = page.getByRole('form', { name: 'Referral programme' });
  // Desktop and phone share this business: start from “off, no percentages”, whatever an earlier run saved.
  await form.getByLabel('Back on their own purchases (%)').fill('0');
  while ((await form.getByRole('button', { name: /^Remove level/ }).count()) > 0) {
    await form.getByRole('button', { name: /^Remove level/ }).click();
  }
  const on = form.getByRole('checkbox', { name: 'Run a referral programme' });
  if (!(await on.isChecked())) await on.click();
  await form.getByRole('button', { name: 'Save' }).click();
  await expect(
    page
      .getByRole('alert')
      .filter({ hasText: 'Set at least one percentage before switching the programme on.' }),
  ).toBeVisible();

  await form.getByLabel('Back on their own purchases (%)').fill('2');
  await form.getByRole('button', { name: 'Add level 1' }).click();
  await form.getByLabel('Level 1 — someone they referred (%)').fill('5');
  await expect(form.getByText('Total: 7% of')).toBeVisible();
  await form.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Referral programme saved — it’s on.')).toBeVisible();

  await page.reload();
  await expect(page.getByLabel('Back on their own purchases (%)')).toHaveValue('2');
  await expect(page.getByLabel('Level 1 — someone they referred (%)')).toHaveValue('5');
  await expect(page.getByRole('checkbox', { name: 'Run a referral programme' })).toBeChecked();
  await expectAccessible(page);

  // Customers → Referrals: nobody in the programme yet for this new business.
  await page.goto(`/${handle}/referrals`);
  await expect(page.getByRole('heading', { level: 1, name: 'Referrals' })).toBeVisible();
  await expect(page.getByRole('search', { name: 'Find a customer by code' })).toBeVisible();
  await expectAccessible(page);
});
