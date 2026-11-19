import { expect, test, type Page } from './fixtures';
import { totp } from './totp';
import { expectAccessible } from './support';

/*
 * Sprint 17 (17.6), the parts a browser can check: an owner's Website → Your domain, and WURK HQ's Admin → Domains
 * and Admin → System's releases. The cloud itself (deploys, rollbacks, alarms, backups, the drill) is checked on
 * staging by the go-live checklist (docs/deployment/go-live.md).
 */

const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
const salon = 'demo-salon-hair-braids';

async function signInHq(page: Page, project: string) {
  const admin = `http://localhost:${process.env.E2E_PORT ?? 8123}`;
  await page.goto(`${admin}/sign-in/password`);
  await page.getByLabel('Email or mobile number').fill(`golive-${project}@wurk.co.za`);
  await page.getByLabel(/^Password/).fill('sunny days in durban');
  await page.getByRole('button', { name: 'Sign in' }).click();
  const codeBox = page.getByRole('group', { name: 'One-time code' }).getByRole('textbox').first();
  await codeBox.fill(totp(SECRET));
  const signedIn = await page.waitForURL(/\/dashboard$/, { timeout: 5000 }).then(
    () => true,
    () => false,
  );
  if (!signedIn) {
    await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 1000); // the code was used: wait for the next one
    await codeBox.fill(totp(SECRET));
  }
  await expect(page).toHaveURL(/\/dashboard$/);
  return admin;
}

test('an owner finds Website → Your domain', async ({ page }) => {
  await page.goto('/sign-in/password');
  await page.getByLabel('Email or mobile number').fill('chat-owner@demo.co.za');
  await page.getByLabel(/^Password/).fill('sunny days in durban');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(new RegExp(`/${salon}$`));
  await page.goto(`/${salon}/website/domain`);
  await expect(page.getByRole('heading', { level: 1, name: 'Your domain' })).toBeVisible();
  await expect(page.getByText(`${salon}.`, { exact: false }).first()).toBeVisible(); // the WURK address is always there
  await expectAccessible(page);
});

test('WURK HQ sees owners’ domains and every release', async ({ page }, testInfo) => {
  test.setTimeout(90_000);
  const admin = await signInHq(page, testInfo.project.name);
  await page.goto(`${admin}/domains`);
  await expect(page.getByRole('heading', { level: 1, name: 'Domains' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${admin}/system`);
  await expect(page.getByText('Releases', { exact: true })).toBeVisible();
  await expect(page.getByText('v1.0.0-rc.1')).toBeVisible();
  await expectAccessible(page);
});
