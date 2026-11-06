import { expect, test } from '@playwright/test';
import { expectAccessible, latestCode, signUpBusiness } from './support';

test('a new customer signs in to the business’s portal with a code, agrees once, sees their account and signs out', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'portal', undefined, 'growth');
  const port = new URL(page.url()).port;
  const portal = `http://${handle}.wurk.test:${port}/account`;
  const digits = String(Date.now()).slice(-7);
  const e164 = `+2783${digits}`;

  await page.goto(portal); // the business's own address: its own session (the owner isn't signed in here)
  await expect(page.getByRole('heading', { level: 1, name: 'Your account' })).toBeVisible();
  await expectAccessible(page);
  await page.getByLabel('Phone number or email').fill(`083${digits}`);
  await page.getByRole('button', { name: 'Send me a code' }).click();
  await expect(page.getByRole('status')).toContainText('We sent a code to');

  await page.getByLabel(/^Code sent to/).fill(latestCode(e164));
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText('Tell us your name to finish signing in.')).toBeVisible(); // a new customer
  await page.getByLabel('Your name').fill(`Lerato ${testInfo.project.name}`);
  await page.getByRole('button', { name: 'Sign in' }).click(); // the same code still works

  await expect(page.getByRole('heading', { level: 1, name: 'Before you continue' })).toBeVisible();
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByText('Please agree to continue.')).toBeVisible();
  await page.getByRole('checkbox').check();
  await page.getByRole('button', { name: 'Continue' }).click();

  await expect(page.getByRole('heading', { level: 1, name: 'Hi Lerato' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Your history' })).toBeVisible();
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('button', { name: 'Send me a code' })).toBeVisible();
});
