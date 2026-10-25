import { expect, test } from '@playwright/test';
import { expectAccessible } from './support';

test('a customer books online on a published site', async ({ page }, testInfo) => {
  test.setTimeout(60_000);
  const port = new URL(String(testInfo.project.use.baseURL)).port;
  await page.goto(`http://demo-salon-hair-braids.wurk.test:${port}/contact`); // bookable demo site (bookings:demo)

  const service = page.getByLabel('Service');
  await expect(service).toBeVisible(); // the island replaced the "call or message us" fallback
  const tomorrow = new Date(Date.now() + 864e5).toLocaleDateString('en-CA', {
    timeZone: 'Africa/Johannesburg',
  });
  await page.getByLabel('Day').fill(tomorrow);
  await page.getByLabel('Day').dispatchEvent('change');
  const times = page.getByRole('group', { name: 'Free times' });
  await expect(times.getByRole('button').first()).toBeVisible();
  await expectAccessible(page);

  await page.getByLabel('Online (video call)').check(); // the demo Consultation lets the client choose
  await times.getByRole('button').first().click();
  await page.getByLabel('Your name').fill('Sipho Ndlovu');
  await page.getByLabel('Phone').fill('082 555 0101');
  await page.getByLabel('I agree to be contacted about my booking.').check();
  await page.getByRole('button', { name: 'Book' }).click();

  await expect(page.getByRole('heading', { name: 'You’re booked' })).toBeVisible();
  // Focus moves to the confirmation (heading and details), so screen readers announce it.
  await expect(page.locator(':focus')).toContainText('You’re booked');
  await expect(page.locator(':focus')).toContainText('Consultation — ');
  await expectAccessible(page);
});
