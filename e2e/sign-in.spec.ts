import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

async function expectNoSeriousA11yIssues(page: Page) {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();
  const serious = results.violations.filter(
    (v) => v.impact === 'serious' || v.impact === 'critical',
  );
  expect(
    serious.map((v) => `${v.id}: ${v.help} (${v.nodes.map((n) => n.target.join(' ')).join(', ')})`),
  ).toEqual([]);
}

for (const theme of ['light', 'dark'] as const) {
  test(`sign-in screens are accessible in ${theme} mode`, async ({ page }, testInfo) => {
    await page.addInitScript((t) => window.localStorage.setItem('wurk-theme', t), theme);

    await page.goto('/sign-in');
    await expect(page.getByRole('heading', { level: 1, name: 'Sign in to WURK' })).toBeVisible();
    await expectNoSeriousA11yIssues(page);

    // A distinct address per run: the 30-second resend limit applies per address.
    await page
      .getByLabel('Email or mobile number')
      .fill(`${theme}-${testInfo.project.name}-${testInfo.retry}@example.com`); // a retry gets its own address (30-second resend rule)
    await page.getByRole('button', { name: 'Send me a code' }).click();
    await expect(page.getByRole('heading', { name: 'Enter your code' })).toBeVisible();
    await expect(page.getByRole('group', { name: 'One-time code' })).toBeVisible();
    await expectNoSeriousA11yIssues(page);

    await page.goto('/sign-in/password');
    await expectNoSeriousA11yIssues(page);
    await page.goto('/forgot-password');
    await expectNoSeriousA11yIssues(page);
  });
}

test('rejects a wrong password with a plain message', async ({ page }) => {
  await page.goto('/sign-in/password');
  await page.getByLabel('Email or mobile number').fill('owner@glow.co.za');
  await page.getByLabel(/^Password/).fill('not the password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText('That email/number and password don’t match.')).toBeVisible();
});

test('an owner signs in and lands in their business, then reviews security and signs out', async ({
  page,
}) => {
  await page.goto('/sign-in/password');
  await page.getByLabel('Email or mobile number').fill('owner@glow.co.za');
  await page.getByLabel(/^Password/).fill('sunny days in durban');
  await page.getByRole('button', { name: 'Sign in' }).click();

  await expect(page).toHaveURL(/\/glow-salon$/);
  await expect(
    page.getByRole('heading', { level: 1, name: 'Welcome to Glow Salon' }),
  ).toBeVisible();
  await expectNoSeriousA11yIssues(page);

  await page.goto('/account/security');
  await expect(page.getByRole('heading', { level: 1, name: 'Account & security' })).toBeVisible();
  await expect(page.getByText('This device')).toBeVisible();
  await expectNoSeriousA11yIssues(page);

  await page.getByRole('button', { name: 'Account menu' }).click();
  await page.getByRole('menuitem', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Sign in to WURK' })).toBeVisible();
  await expect(page.getByRole('status')).toHaveText('You’re signed out.');
});

test('signed-out visitors are sent to sign in, without revealing which businesses exist', async ({
  page,
}) => {
  for (const path of ['/glow-salon', '/no-such-business']) {
    await page.goto(path);
    await expect(page).toHaveURL(/\/sign-in$/);
  }
});
