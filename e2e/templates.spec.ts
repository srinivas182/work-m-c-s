import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner picks a design at signup, gets a full site, and can switch design', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'tpl', {
    category: 'Salon & beauty',
    design: 'Bold salon (sample)',
  });

  // The design's pages are there from the start.
  await page.goto(`/${handle}/website/pages`);
  await expect(
    page.getByRole('list', { name: 'Pages' }).getByText('Prices', { exact: true }),
  ).toBeVisible();
  await expect(page.getByText(/You’re using/)).toContainText('Bold salon (sample)');
  await expectAccessible(page);

  // Switch to the other design in the category; content is kept.
  await page.getByRole('button', { name: 'Use this design: Warm salon (sample)' }).click();
  await expect(page.getByText(/Design changed/)).toBeVisible();
  await expect(page.getByText(/You’re using/)).toContainText('Warm salon (sample)');
  await expect(
    page.getByRole('list', { name: 'Pages' }).getByText('Prices', { exact: true }),
  ).toBeVisible();
});

test('designs can be previewed before choosing', async ({ page }) => {
  await page.goto('/templates/sample-salon-warm/preview');
  await expect(
    page.getByText('Sample content — your own details and branding replace it.'),
  ).toBeVisible();
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await expectAccessible(page);
  await page.getByRole('combobox', { name: 'Page' }).selectOption({ label: 'Prices' });
  await expect(page).toHaveURL(/page=prices/);
});
