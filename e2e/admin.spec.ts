import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { totp } from './totp';

const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

async function expectAccessible(page: Page) {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();
  const serious = results.violations.filter(
    (v) => v.impact === 'serious' || v.impact === 'critical',
  );
  expect(
    serious.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`),
  ).toEqual([]);
}

// The admin host is "localhost" in the e2e environment; the business back office is 127.0.0.1.
test('WURK staff sign in with an authenticator and run the admin', async ({ page }, testInfo) => {
  test.setTimeout(90_000);
  const admin = `http://localhost:${process.env.E2E_PORT ?? 8123}`;

  await page.goto(`${admin}/sign-in/password`);
  await expect(
    page
      .getByRole('heading', { name: 'WURK staff sign in' })
      .or(page.getByRole('heading', { name: 'Sign in with password' })),
  ).toBeVisible();
  await page.getByLabel('Email or mobile number').fill(`ops-${testInfo.project.name}@wurk.co.za`);
  await page.getByLabel(/^Password/).fill('sunny days in durban');
  await page.getByRole('button', { name: 'Sign in' }).click();

  await expect(page.getByRole('heading', { name: 'Two-step verification' })).toBeVisible();
  // Authenticator codes are single-use. If this code was already used (e.g. on a
  // test retry within the same 30 seconds), wait for the next one.
  const codeBox = page.getByRole('group', { name: 'One-time code' }).getByRole('textbox').first();
  await codeBox.fill(totp(SECRET));
  const signedIn = await page.waitForURL(/\/dashboard$/, { timeout: 5000 }).then(
    () => true,
    () => false,
  );
  if (!signedIn) {
    await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 1000);
    await codeBox.fill(totp(SECRET));
  }
  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
  await expectAccessible(page);

  const handle = `e2e-${testInfo.project.name}-${Date.now().toString(36)}`;
  await page.goto(`${admin}/businesses/new`);
  await expect(page.getByRole('heading', { level: 1, name: 'New business' })).toBeVisible();
  await expectAccessible(page);
  await page.getByLabel(/^Business name/).fill(`E2E ${testInfo.project.name} Studio`);
  await page.getByLabel(/^Web address/).fill(handle);
  await page.getByLabel(/^First name/).fill('Sipho');
  await page.getByLabel(/^Email/).fill(`${handle}@example.com`);
  await page.getByRole('button', { name: 'Create business and invite owner' }).click();
  await expect(
    page.getByRole('heading', { level: 1, name: `E2E ${testInfo.project.name} Studio` }),
  ).toBeVisible();
  await expect(page.getByText(`${handle}.wurk.test`).first()).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/businesses`);
  await expect(page.getByRole('link', { name: 'Glow Salon' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/staff`);
  await expect(page.getByRole('heading', { level: 1, name: 'WURK staff' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/roles`);
  await expect(page.getByRole('heading', { level: 1, name: 'Staff roles' })).toBeVisible();
  await expectAccessible(page);
});
