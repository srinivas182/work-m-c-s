import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner sets up menus and branding, then previews the site', async ({ page }, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'site');

  // A second page to link to.
  await page.goto(`/${handle}/website/pages`);
  await page.getByRole('button', { name: 'Add page' }).click();
  await page.getByLabel(/^Page title/).fill('Specials');
  await page.getByRole('dialog').getByRole('button', { name: 'Add page' }).click();
  await expect(page.getByText('/specials')).toBeVisible();

  // Menus: link the header to the Specials page.
  await page.goto(`/${handle}/website/menus`);
  await expect(page.getByRole('heading', { level: 1, name: 'Menus' })).toBeVisible();
  await page.getByRole('button', { name: 'Add a link' }).first().click();
  await page.getByRole('textbox', { name: 'Menu label' }).last().fill('Our prices');
  await page.getByRole('combobox', { name: 'Page' }).last().click();
  await page.getByRole('option', { name: 'Specials' }).click();
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Save menus' }).click();
  await expect(page.getByText('Menus saved.')).toBeVisible();

  // Branding: colour and fonts, with the live preview updating before saving.
  await page.goto(`/${handle}/website/branding`);
  await expect(page.getByRole('heading', { level: 1, name: 'Branding' })).toBeVisible();
  await page.getByRole('textbox', { name: 'Main colour hex code' }).fill('#0F766E');
  await page.getByRole('radio', { name: /Editorial/ }).click();
  await expect(page.getByText('Live preview — not saved yet')).toBeVisible();
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Save branding' }).click();
  await expect(page.getByText('Branding saved.')).toBeVisible();

  // Preview: the whole site, menu links stay inside the preview.
  await page.goto(`/${handle}/website/preview`);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await expectAccessible(page);
  const nav =
    testInfo.project.name === 'mobile' ? page.locator('summary[aria-label="Menu"]') : null;
  if (nav) await nav.click();
  await page.getByRole('link', { name: 'Our prices' }).first().click();
  await expect(page).toHaveURL(/\/website\/preview\?page=specials$/);
  // No Hero on this page: its first section's heading is the page's main heading.
  await expect(page.getByRole('heading', { level: 1, name: 'Specials' })).toBeVisible();
});
