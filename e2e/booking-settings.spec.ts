import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner sets up a service, their hours and time off', async ({ page }, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'bookings'); // Starter includes bookings

  await page.goto(`/${handle}/bookings/settings`);
  await expect(page.getByRole('heading', { level: 1, name: 'Booking settings' })).toBeVisible();
  await expectAccessible(page);

  const add = page.getByRole('form', { name: 'Add a service' });
  await add.getByLabel('Name').fill('Knotless braids');
  await add.getByLabel('Length (minutes)').fill('60');
  await add.getByLabel('Buffer after (minutes)').fill('15');
  await add.getByLabel('Price (R)').fill('450');
  await add.getByRole('button', { name: 'Save service' }).click();
  await expect(page.getByText('Service saved.')).toBeVisible();
  await expect(page.getByText(/60 min \+ 15 min after · R450/)).toBeVisible();

  const hours = page.getByRole('region', { name: /’s hours$/ }).first();
  await hours.getByRole('checkbox', { name: 'Monday' }).click();
  await expect(hours.getByLabel('Monday start 1')).toHaveValue('09:00');
  await hours.getByRole('button', { name: /^Save .*’s hours$/ }).click();
  await expect(page.getByText('Hours saved.')).toBeVisible();

  const off = page.getByRole('form', { name: 'Add time off' });
  await off.getByLabel('From').fill('2027-04-27T00:00');
  await off.getByLabel('Until').fill('2027-04-28T00:00');
  await off.getByLabel('Reason (optional)').fill('Freedom Day');
  await off.getByRole('button', { name: 'Add time off' }).click();
  await expect(page.getByText(/Everyone · .* · Freedom Day/)).toBeVisible();

  // Before WURK switches on payments, owners are told plainly; the policy can still be set.
  await expect(
    page.getByText(
      'Online payments aren’t switched on yet. Bookings work without deposits in the meantime.',
    ),
  ).toBeVisible();
  const policy = page.getByRole('form', { name: 'Cancellation policy' });
  await policy.getByLabel('Free cancellation until (hours before)').fill('48');
  await policy.getByRole('button', { name: 'Save policy' }).click();
  await expect(page.getByText('Cancellation policy saved.')).toBeVisible();

  const messages = page.getByRole('form', { name: 'Confirmations and reminders' });
  await expect(messages.getByRole('radio', { name: /Email — Free\./ })).toBeChecked();
  await messages.getByRole('checkbox', { name: '2 hours before' }).click();
  await messages.getByRole('button', { name: 'Save message settings' }).click();
  await expect(page.getByText('Message settings saved.')).toBeVisible();

  // Video calls: Google and Zoom need WURK's apps (not in testing); the fixed link works now.
  await expect(page.getByText('Google Meet — not switched on yet')).toBeVisible();
  const video = page.getByRole('form', { name: 'Use a fixed meeting link' });
  await video
    .getByLabel('Or use one meeting link for everyone')
    .fill('https://meet.example.com/room');
  await video.getByRole('button', { name: 'Save link' }).click();
  await expect(
    page.getByText('Your meeting link is saved. Note: every client gets the same link.'),
  ).toBeVisible();
  await expectAccessible(page);
});
