import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { totp } from './totp';

const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

async function expectAccessible(page: Page) {
  // Scan what people actually read: wait for toasts to finish their fade-in
  // (axe measures contrast at the current opacity, so a half-faded toast fails).
  await page.waitForFunction(() =>
    Array.from(document.querySelectorAll('[data-sonner-toast]')).every(
      (t) => getComputedStyle(t).opacity === '1',
    ),
  );
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
  await page.getByLabel(/^Email or mobile number/).fill(`${handle}@example.com`);
  await page.getByRole('button', { name: 'Create business and invite owner' }).click();
  await expect(
    page.getByRole('heading', { level: 1, name: `E2E ${testInfo.project.name} Studio` }),
  ).toBeVisible();
  await expect(page.getByText(`${handle}.wurk.test`).first()).toBeVisible();
  await expectAccessible(page);

  await page.getByRole('link', { name: 'Cash payment' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Cash payments' })).toBeVisible();
  await expectAccessible(page);

  // Find it as staff would: the list is newest first, 25 a page, and setup also creates the demo sites.
  await page.goto(`${admin}/businesses?search=Glow`);
  await expect(page.getByRole('link', { name: 'Glow Salon' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/staff`);
  await expect(page.getByRole('heading', { level: 1, name: 'WURK staff' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/packages/new`);
  await expect(page.getByRole('heading', { level: 1, name: 'New package' })).toBeVisible();
  await expectAccessible(page);
  await page.getByLabel(/^Name/).fill(`E2E ${testInfo.project.name}`);
  await page.getByLabel(/^Key/).fill(`e2e-${testInfo.project.name}-${Date.now().toString(36)}`);
  await page.getByLabel(/^Monthly price/).fill('299');
  await page.getByLabel(/^Yearly price/).fill('2990');
  await page.getByLabel('Appointments').check();
  await page.getByRole('button', { name: 'Create package' }).click();
  await expect(page.getByText('Version 1')).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/packages`);
  await expect(page.getByRole('heading', { level: 1, name: 'Packages' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/features`);
  await expect(page.getByRole('heading', { level: 1, name: 'Feature rollouts' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/providers`);
  await expect(page.getByRole('heading', { level: 1, name: 'Providers' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/countries`);
  await expect(page.getByRole('heading', { level: 1, name: 'Countries' })).toBeVisible();
  await expect(page.getByRole('cell', { name: 'South Africa · ZA' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/credits/rates`);
  await expect(page.getByRole('heading', { level: 1, name: 'Credit rates' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/security`);
  await expect(page.getByRole('heading', { level: 1, name: 'Security' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/billing/settings`);
  await expect(page.getByRole('heading', { level: 1, name: 'Invoice settings' })).toBeVisible();
  await expect(page.getByText('These are placeholder company details.')).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/billing/failed`);
  await expect(page.getByRole('heading', { level: 1, name: 'Failed payments' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/templates`);
  await expect(page.getByRole('heading', { level: 1, name: 'Website templates' })).toBeVisible();
  await expectAccessible(page);
  await page.getByRole('link', { name: /Warm salon \(sample\)/ }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Warm salon (sample)' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/launch-times`);
  await expect(page.getByRole('heading', { level: 1, name: 'Time to launch' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/settings/ai`);
  await expect(page.getByRole('heading', { level: 1, name: 'WURK AI' })).toBeVisible();
  await expectAccessible(page);
  await page.goto(`${admin}/packages`);
  await page.getByRole('link', { name: 'Edit Starter' }).click();
  await expect(page.getByRole('note', { name: 'Estimated cost of these credits' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/roles`);
  await expect(page.getByRole('heading', { level: 1, name: 'Staff roles' })).toBeVisible();
  await expectAccessible(page);
});
