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
  // The dashboard (Sprint 14.6a).
  await expect(page.getByRole('list', { name: 'At a glance' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Next payout' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Earnings by month' })).toBeVisible();
  await expectAccessible(page);

  await page
    .getByRole('navigation', { name: 'Agent area' })
    .getByRole('link', { name: 'My businesses' })
    .click();
  await expect(page.getByRole('heading', { level: 1, name: 'My businesses' })).toBeVisible();
  await expect(page.getByText('No businesses yet')).toBeVisible();
  await expectAccessible(page);

  // Earnings, team, payouts and the marketing kit (Sprint 14.6b).
  const nav = page.getByRole('navigation', { name: 'Agent area' });
  for (const [link, heading, shows] of [
    ['Earnings', 'Earnings', 'No earnings in this period'],
    ['Team', 'Your team', 'No team yet'],
    ['Payouts', 'Payouts', 'No payouts yet'],
  ] as const) {
    await nav.getByRole('link', { name: link, exact: true }).click();
    await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
    await expect(page.getByText(shows)).toBeVisible();
    await expectAccessible(page);
  }
  await nav.getByRole('link', { name: 'Marketing kit' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Marketing kit' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Share on WhatsApp' })).toHaveAttribute(
    'href',
    /^https:\/\/wa\.me\/\?text=/,
  );
  await expect(page.getByRole('link', { name: 'Download QR code' })).toBeVisible();
  await expectAccessible(page);

  // Demo site (Sprint 14.8a): make one from a real template, then delete it (so re-runs start clean).
  await nav.getByRole('link', { name: 'Demo site' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Demo site' })).toBeVisible();
  await expectAccessible(page);
  const demo = page.getByRole('form', { name: 'Make a demo site' });
  await demo.getByLabel('Business name').fill(`Test Braids ${testInfo.project.name}`);
  await demo.getByLabel('Industry').selectOption({ index: 1 });
  await demo.getByLabel('Template').selectOption({ index: 1 });
  await demo.getByRole('button', { name: 'Make the demo' }).click();
  await expect(page.getByText(/^Your demo is live\./)).toBeVisible();
  await expect(page.getByRole('link', { name: 'Edit it in the back office' })).toBeVisible();
  await expectAccessible(page);
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Delete demo' }).click();
  await expect(page.getByText('Demo deleted. You can make another.')).toBeVisible();

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
