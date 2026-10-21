import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test('an owner sees team changes in their audit log and can export it', async ({ page }) => {
  await page.goto('/sign-in/password');
  await page.getByLabel('Email or mobile number').fill('owner@glow.co.za');
  await page.getByLabel(/^Password/).fill('sunny days in durban');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/glow-salon$/);

  await page.goto('/glow-salon/audit');
  await expect(page.getByRole('heading', { level: 1, name: 'Audit log' })).toBeVisible();
  await expect(
    page.getByRole('region', { name: 'Audit events' }).getByText('Role given').first(),
  ).toBeVisible();
  await expect(page.getByRole('link', { name: 'Export CSV' })).toHaveAttribute(
    'href',
    /format=csv/,
  );

  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(
    results.violations
      .filter((v) => v.impact === 'serious' || v.impact === 'critical')
      .map((v) => v.id),
  ).toEqual([]);

  await page.getByRole('button', { name: 'Show details' }).first().click();
  await expect(page.getByText('"role": "owner"')).toBeVisible();
});
