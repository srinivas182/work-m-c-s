import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('a guest reserves a table on a published restaurant site', async ({ page }, testInfo) => {
  test.setTimeout(60_000);
  const port = new URL(String(testInfo.project.use.baseURL)).port;
  await page.goto(`http://demo-hospitality-restaurant.wurk.test:${port}/contact`);
  await expect(page.getByLabel('Guests')).toBeVisible();
  const tomorrow = new Date(Date.now() + 864e5).toLocaleDateString('en-CA', {
    timeZone: 'Africa/Johannesburg',
  });
  await page.getByLabel('Guests').selectOption('4');
  await page.getByLabel('Day').fill(tomorrow);
  await page.getByLabel('Day').dispatchEvent('change');
  const times = page.getByRole('group', { name: 'Free times' });
  await expect(times.getByRole('button').first()).toBeVisible();
  await expectAccessible(page);
  await times.getByRole('button').first().click();
  await page.getByLabel('Your name').fill('Sipho Ndlovu');
  await page.getByLabel('Phone').fill('082 555 0101');
  await page.getByLabel('I agree to be contacted about my reservation.').check();
  await page.getByRole('button', { name: 'Reserve' }).click();
  await expect(page.locator(':focus')).toContainText('You’re booked');
  await expect(page.locator(':focus')).toContainText('Table for 4');
  await expectAccessible(page);
});

test('an owner sets up tables, adds a reservation and seats the guests', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'tables');
  await page.goto(`/${handle}/bookings/tables?date=2027-05-03`);
  await expect(page.getByRole('heading', { level: 1, name: 'Tables' })).toBeVisible();

  const settings = page.getByRole('form', { name: 'Table settings' });
  await settings.getByRole('checkbox', { name: 'Take table reservations online' }).click();
  await settings.getByRole('checkbox', { name: 'Monday' }).click();
  await settings.getByRole('button', { name: 'Save table settings' }).click();
  await expect(page.getByText('Table settings saved.')).toBeVisible();

  const add = page.getByRole('form', { name: 'Add a reservation' });
  await add.getByLabel('Guests').fill('4');
  await add.getByLabel('Name').fill('Lerato');
  await add.getByRole('button', { name: 'Add reservation' }).click();
  await expect(page.getByText('Reservation added.')).toBeVisible();
  await expect(page.getByText('19:00 · Lerato · 4 guests')).toBeVisible();
  await page.getByRole('button', { name: 'Seated: Lerato at 19:00' }).click();
  await expect(page.getByText('Marked as seated.')).toBeVisible();
  await expectAccessible(page);
});
