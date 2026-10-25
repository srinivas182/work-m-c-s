import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner adds a walk-in to the calendar, moves it and marks it done', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'calendar');

  await page.goto(`/${handle}/bookings/settings`);
  const add = page.getByRole('form', { name: 'Add a service' });
  await add.getByLabel('Name').fill('Knotless braids');
  await add.getByLabel('Length (minutes)').fill('60');
  await add.getByRole('button', { name: 'Save service' }).click();
  await expect(page.getByText('Service saved.')).toBeVisible();

  await page.goto(`/${handle}/bookings?date=2027-05-03`);
  await expect(page.getByRole('heading', { level: 1, name: 'Calendar' })).toBeVisible();
  await expectAccessible(page);

  const form = page.getByRole('form', { name: 'Add a booking' });
  await form.getByLabel('Time').fill('10:00');
  await form.getByLabel('Customer name').fill('Sipho');
  await form.getByRole('button', { name: 'Add booking' }).click();
  await expect(page.getByText('Booking added.')).toBeVisible();
  await expect(page.getByText('10:00–11:00 · Sipho')).toBeVisible();

  await page.getByRole('button', { name: 'Move Sipho’s booking at 10:00' }).click();
  const move = page.getByRole('form', { name: 'Move Sipho’s booking' });
  await move.getByLabel('Time').fill('12:00');
  await move.getByRole('button', { name: 'Move' }).click();
  await expect(page.getByText('Booking moved.')).toBeVisible();
  await expect(page.getByText('12:00–13:00 · Sipho')).toBeVisible();

  await page.getByRole('button', { name: 'Done: Sipho at 12:00' }).click();
  await expect(page.getByText('Marked as done.')).toBeVisible();
  await expectAccessible(page);
});
