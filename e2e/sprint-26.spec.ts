import { expect, test, type Page } from './fixtures';
import { expectAccessible } from './support';

/*
 * Sprint 26 (26.6), as an owner uses it on their phone: Home's "Needs you now", Today, phone notifications, the
 * week's shifts filled from booking hours, the team's earnings for the bookkeeper, and the loyalty card.
 */

const salon = 'demo-salon-hair-braids';

async function signInOwner(page: Page) {
  await page.goto('/sign-in/password');
  await page.getByLabel('Email or mobile number').fill('chat-owner@demo.co.za');
  await page.getByLabel(/^Password/).fill('sunny days in durban');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(new RegExp(`/${salon}$`));
}

test('Home shows what needs the owner now, and Today and phone notifications open', async ({
  page,
}) => {
  await signInOwner(page);
  await expect(page.getByText('Needs you now', { exact: true })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`/${salon}/today`);
  await expect(page.getByRole('heading', { level: 1, name: 'Today' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`/${salon}/settings/notifications`);
  await expect(
    page.getByRole('heading', { level: 1, name: 'Notifications on my phone' }),
  ).toBeVisible();
  await expectAccessible(page);

  // Installable from the browser: the app opens on Today, and the service worker handles pushes.
  const manifest = (await (await page.request.get('/manifest.webmanifest')).json()) as {
    start_url: string;
    display: string;
  };
  expect(manifest.start_url).toBe('/businesses?to=today');
  expect(await (await page.request.get('/sw.js')).text()).toContain("addEventListener('push'");
});

test('the owner fills the week’s shifts from booking hours and sees the team’s earnings', async ({
  page,
}) => {
  await signInOwner(page);
  await page.goto(`/${salon}/shifts`);
  await expect(page.getByRole('heading', { level: 1, name: 'Shifts' })).toBeVisible();
  await page.getByRole('button', { name: 'Fill from booking hours' }).click();
  await expect(
    page.getByText(/shifts? added from booking hours\.|Nothing to add: everyone’s booking hours/),
  ).toBeVisible();
  await expectAccessible(page);

  await page.goto(`/${salon}/shifts/earnings`);
  await expect(page.getByRole('heading', { level: 1, name: 'Team earnings' })).toBeVisible();
  await expect(page.getByText(/Not payroll/).first()).toBeVisible();
  await expectAccessible(page);
});

test('the owner sets up the loyalty card customers follow on WhatsApp', async ({ page }) => {
  await signInOwner(page);
  await page.goto(`/${salon}/customers/loyalty`);
  await expect(page.getByRole('heading', { level: 1, name: 'Loyalty card' })).toBeVisible();
  await page.getByLabel('Give customers a loyalty card').check();
  await page.getByLabel('Stamps for a reward').fill('8');
  await page.getByLabel('The reward, in your words').fill('your free cut');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved.')).toBeVisible();
  await expect(
    page.getByText('What customers see: “6 of 8 — 2 more to your free cut.”'),
  ).toBeVisible();
  await expectAccessible(page);
});
