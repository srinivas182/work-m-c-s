// The agent area (Sprint 14.7): signing in, home, and banking details up to the confirmation code.
import { expect, test } from '@playwright/test';
import { expectAccessible } from './support';

test('an agent signs in, sees their link and asks to change their banking details', async ({
  page,
}, testInfo) => {
  await page.goto('/sign-in/password');
  await page.getByLabel('Email or mobile number').fill(`agent-${testInfo.project.name}@wurk.co.za`);
  await page.getByLabel(/^Password/).fill('sunny days in durban');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).not.toHaveURL(/sign-in/);

  await page.goto('/agent');
  await expect(page.getByRole('heading', { level: 1, name: 'Your agent area' })).toBeVisible();
  await expect(page.getByText(/Your link:/)).toBeVisible();
  await expectAccessible(page);

  await page
    .getByRole('navigation', { name: 'Agent area' })
    .getByRole('link', { name: 'Banking details' })
    .click();
  await expect(page.getByRole('heading', { level: 1, name: 'Banking details' })).toBeVisible();
  await expectAccessible(page);
  const form = page.getByRole('form', { name: 'Your banking details' });
  await form.getByLabel('Account holder').fill('D Agent');
  await form.getByLabel('Bank').selectOption('FNB');
  await form.getByLabel('Branch code').fill('250655');
  await form.getByLabel('Account number').fill('62123456789');
  await form.getByRole('button', { name: 'Send me a code' }).click();
  // The code goes to their phone or email; the backend tests confirm it (the browser can't read it).
  await expect(page.getByText(/We sent a code to/)).toBeVisible();
  await expect(page.getByRole('form', { name: 'Confirm your banking details' })).toBeVisible();
  await expectAccessible(page);
});
