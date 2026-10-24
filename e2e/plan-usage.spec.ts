import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner sees their plan and usage, sets a spending limit, and can download the statement', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'plan');

  await page.goto(`/${handle}/settings/credits`);
  await expect(page.getByRole('heading', { level: 1, name: 'Plan & usage' })).toBeVisible();
  await expect(page.getByRole('progressbar', { name: 'Team members' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Download statement (CSV)' })).toHaveAttribute(
    'href',
    /statement\.csv$/,
  );
  await expectAccessible(page);

  await page.getByLabel('Limit (credits per month)').fill('40');
  await page.getByRole('button', { name: 'Save' }).first().click();
  await expect(page.getByText('Spending is now limited to 40 credits a month.')).toBeVisible();
  await expect(page.getByLabel('Limit (credits per month)')).toHaveValue('40');
});
