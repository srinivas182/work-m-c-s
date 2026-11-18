import { expect, test } from './fixtures';
import { expectAccessible, signUpBusiness } from './support';

/*
 * Sprint 22: payment journeys a person does in the browser. Providers themselves (Paystack, M-Pesa, MTN MoMo,
 * Airtel Money, Flutterwave) are covered by feature tests against recorded sandbox responses.
 */

test('an owner records a deposit and the rest of the price paid in person on a booking', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'cash');

  await page.goto(`/${handle}/bookings/settings`);
  const add = page.getByRole('form', { name: 'Add a service' });
  await add.getByLabel('Name').fill('Knotless braids');
  await add.getByLabel('Length (minutes)').fill('60');
  await add.getByLabel(/Price/).fill('450');
  await add.getByRole('button', { name: 'Save service' }).click();
  await expect(page.getByText('Service saved.')).toBeVisible();

  await page.goto(`/${handle}/bookings?date=2027-05-03`);
  const form = page.getByRole('form', { name: 'Add a booking' });
  await form.getByLabel('Time').fill('10:00');
  await form.getByLabel('Customer name').fill('Lerato');
  await form.getByRole('button', { name: 'Add booking' }).click();
  await expect(page.getByText('Booking added.')).toBeVisible();

  await page.getByRole('button', { name: 'Paid in person: Lerato at 10:00' }).click();
  const pay = page.getByRole('form', { name: 'Record a payment from Lerato' });
  await expect(pay.getByLabel('Amount (R)')).toHaveValue('450.00'); // what's left of the price
  await pay.getByLabel('Amount (R)').fill('100');
  await pay.getByRole('button', { name: 'Record' }).click();
  await expect(page.getByText('R100.00 recorded: cash.')).toBeVisible();
  await expect(page.getByText(/Paid R100\.00 of R450\.00/)).toBeVisible();

  await page.getByRole('button', { name: 'Paid in person: Lerato at 10:00' }).click();
  await pay.getByLabel('How they paid').selectOption('card_machine');
  await expect(pay.getByLabel('Amount (R)')).toHaveValue('350.00');
  await pay.getByRole('button', { name: 'Record' }).click();
  await expect(page.getByText('R350.00 recorded: card machine.')).toBeVisible();
  await expect(page.getByText(/Paid R450\.00 of R450\.00/)).toBeVisible();
  await expectAccessible(page);
});

test('an owner sees Paystack’s ways to pay, and a key Paystack refuses is explained', async ({
  page,
}, testInfo) => {
  const handle = await signUpBusiness(page, testInfo, 'paystack');
  await page.goto(`/${handle}/settings/payments`);
  await expect(page.getByRole('heading', { name: /^Paystack/ })).toBeVisible();
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Add test keys' }).nth(4).click(); // Yoco, PayFast, Ozow, Peach, then Paystack
  const keys = page.getByRole('form', { name: 'Paystack test keys' });
  await keys.getByLabel('Secret key').fill('sk_live_wrongmode');
  await keys.getByRole('button', { name: 'Save test keys' }).click();
  await expect(
    page.getByText('Paste your Paystack test secret key — it starts with sk_test_.'),
  ).toBeVisible();
});
