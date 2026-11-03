import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner rewords a booking email within the rails, sends a test and switches on the auto-reply', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'messages');
  await page.goto(`/${handle}/settings/messages`);
  await expect(page.getByRole('heading', { level: 1, name: 'Messages' })).toBeVisible();
  await expectAccessible(page);

  const sms = page.getByRole('region', { name: 'Booking confirmed — SMS' });
  await sms.getByLabel('Message').fill('Hi {client}, see you {when}.');
  await sms.getByRole('button', { name: 'Save' }).click();
  await expect(sms.getByText('Keep {link} — customers need it.')).toBeVisible();

  const email = page.getByRole('region', { name: 'Booking confirmed — Email' });
  await email.getByLabel('Subject').fill('See you soon, {client}!');
  await expect(email.getByText('Subject: See you soon, Thandi!')).toBeVisible(); // live preview
  await email.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Message saved.')).toBeVisible();
  await expect(
    page.getByRole('region', { name: 'Booking confirmed — Email' }).getByText('Your wording'),
  ).toBeVisible();

  await page
    .getByRole('region', { name: 'Booking confirmed — Email' })
    .getByRole('button', { name: 'Send me a test email' })
    .click();
  // This owner signed up with a mobile number only: the screen says what to do (the email send itself is covered by the backend tests).
  await expect(
    page
      .getByRole('alert')
      .filter({ hasText: 'Add an email address to your profile to send yourself a test.' }),
  ).toBeVisible();

  await page.getByRole('checkbox', { name: 'Send this message' }).click();
  // The saved state itself (sturdier than the brief confirmation): ticked, and the message marked On.
  await expect(page.getByRole('checkbox', { name: 'Send this message' })).toBeChecked();
  await expect(
    page
      .getByText('“We got your message” auto-reply')
      .locator('..')
      .getByText('On', { exact: true }),
  ).toBeVisible();
  await expectAccessible(page);
});
