import { readFileSync } from 'node:fs';
import { expect, test, type Page } from './fixtures';
import { totp } from './totp';
import { expectAccessible, signUpBusiness } from './support';

/*
 * Sprint 25 (25.7), as people use it: a basic phone books by USSD (posting to WURK's webhook as Africa's Talking
 * would), a customer answers a review request, an owner refunds an invoice, and WURK HQ approves a verified business.
 * The USSD settings, the review request and the HQ staff come from scripts/e2e-setup (scripts/e2e-sprint25.php).
 */

const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
const salon = 'demo-salon-hair-braids';

async function signInOwner(page: Page) {
  await page.goto('/sign-in/password');
  await page.getByLabel('Email or mobile number').fill('chat-owner@demo.co.za');
  await page.getByLabel(/^Password/).fill('sunny days in durban');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(new RegExp(`/${salon}$`));
}

test('a basic phone books a time by USSD, using the number shown in Settings → Bookings by phone', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  await signInOwner(page);
  await page.goto(`/${salon}/settings/reach`);
  await expect(page.getByRole('heading', { level: 1, name: 'Bookings by phone' })).toBeVisible();
  await expectAccessible(page);
  const number = (
    (await page
      .getByText(/^Your number/)
      .locator('strong')
      .textContent()) ?? ''
  ).trim();
  expect(number).toMatch(/^\d{4,}$/);

  // Africa's Talking sends every answer so far, joined by "*"; WURK answers "CON …" (more) or "END …" (done).
  const phone = `+2782555${String(Date.now()).slice(-4)}`;
  const session = `ATUid_e2e_${testInfo.project.name}_${Date.now()}`;
  const answers: string[] = [];
  const dial = async (answer?: string) => {
    if (answer !== undefined) answers.push(answer);
    const res = await page.request.post('/webhooks/ussd/africastalking/e2e-ussd-token-0001', {
      form: {
        sessionId: session,
        phoneNumber: phone,
        serviceCode: '*384*1234#',
        text: answers.join('*'),
      },
    });
    expect(res.ok()).toBe(true);
    return res.text();
  };
  expect(await dial()).toBe("CON WURK bookings\nEnter the business's number:");
  expect(await dial(number)).toMatch(/^CON .+\n1 Book\n2 My bookings\n3 What I owe\n0 Exit$/);
  expect(await dial('1')).toMatch(/^CON Choose a service:\n1 /);
  expect(await dial('1')).toMatch(/\nChoose a day:\n1 /);
  expect(await dial('1')).toMatch(/\nChoose a time:\n1 \d{2}:\d{2}/);
  expect(await dial('1')).toBe('CON Your name for the booking:');
  expect(await dial('Thandi Mokoena')).toMatch(/^CON Book .+\?\n1 Yes\n2 No$/);
  expect(await dial('1')).toMatch(/^END Booked: .+\. A confirmation is on its way\.$/);
});

test('a customer answers a review request after their visit, and the owner sees it', async ({
  page,
}) => {
  test.setTimeout(60_000);
  const { review } = JSON.parse(readFileSync('storage/app/e2e-sprint25.json', 'utf8')) as {
    review: string;
  };
  const customer = await page.context().newPage();
  await customer.goto(review);
  // Desktop and phone share the request: whichever answers first, the other sees the thank-you.
  const ask = customer.getByRole('heading', { level: 1, name: 'How was your Knotless braids?' });
  if (await ask.isVisible()) {
    await expectAccessible(customer);
    await customer.getByText('5 ★').click(); // the star buttons are the radios' labels
    await expect(customer.getByRole('radio', { name: '5 ★' })).toBeChecked();
    await customer.getByLabel('Tell others about it (optional)').fill('Beautiful braids, so neat!');
    await customer.getByRole('button', { name: 'Send' }).click();
  }
  await expect(customer.getByRole('heading', { level: 1, name: 'Thank you' })).toBeVisible();
  await expectAccessible(customer);

  await signInOwner(page);
  await page.goto(`/${salon}/reviews`);
  await expect(page.getByRole('heading', { level: 1, name: 'Reviews' })).toBeVisible();
  await expect(page.getByText('Beautiful braids, so neat!')).toBeVisible();
  await expect(page.getByText('Thandi M.').first()).toBeVisible();
  await expectAccessible(page);
});

test('an owner refunds part of a paid invoice, with a reason, and the customer’s invoice shows it', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'refund');
  await page.goto(`/${handle}/money/owed`);
  await page.getByLabel('Customer’s name').fill('Lerato Dlamini');
  await page.getByLabel('Line 1: description').fill('Wedding hair');
  await page.getByLabel('Line 1: price each (R)').fill('400');
  await page.getByRole('button', { name: 'Save and send' }).click();
  await page.getByRole('button', { name: 'Mark paid: INV-0001' }).click();
  await page.getByRole('button', { name: 'Cash: INV-0001' }).click();
  await expect(page.getByText('INV-0001 marked paid.')).toBeVisible();

  await page.getByRole('button', { name: 'Refund INV-0001' }).click();
  await page.getByLabel('Amount (R)').fill('100');
  await page.getByRole('button', { name: 'Refund', exact: true }).click();
  await expect(page.getByText('Say why — it’s kept with the refund.')).toBeVisible();
  await page.getByLabel('Why (kept with the refund)').fill('Started an hour late');
  await page.getByLabel('Note for the customer (optional)').fill('Sorry about the wait, Lerato.');
  await page.getByRole('button', { name: 'Refund', exact: true }).click();
  await expect(page.getByText('INV-0001 refunded — the customer has been told.')).toBeVisible();
  await expectAccessible(page);

  // Cash-up counts it as cash out today.
  await page.goto(`/${handle}/money/cash-up`);
  await expect(page.getByRole('heading', { level: 1, name: 'Cash-up' })).toBeVisible();
  await expect(page.getByText('R300.00').first()).toBeVisible(); // expected cash: R400 in, R100 back
});

test('an owner sends their ID, WURK HQ approves it, and the verified badge appears', async ({
  page,
}, testInfo) => {
  test.setTimeout(150_000); // includes up to 30 s waiting for a fresh authenticator code
  const handle = await signUpBusiness(page, testInfo, 'verified');
  const name = `E2E Studio ${handle.split('-').at(-1)}`;
  await page.goto(`/${handle}/settings/verified`);
  await expect(page.getByRole('heading', { level: 1, name: 'Verified business' })).toBeVisible();
  await expect(page.getByText('Not verified yet. Complete the checks below.')).toBeVisible();
  await expectAccessible(page);
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    'base64',
  ); // a 1×1 picture: the check here is the flow, not the photo
  await page
    .getByLabel('Your ID document (ID card, ID book or passport)')
    .setInputFiles({ name: 'id.png', mimeType: 'image/png', buffer: png });
  await page
    .getByLabel('A photo of you holding the same ID')
    .setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: png });
  await page.getByRole('button', { name: 'Send for checking' }).first().click();
  await expect(page.getByText('Sent. WURK checks it within two working days.')).toBeVisible();

  // WURK HQ (the admin site), signed in with an authenticator.
  const admin = `http://localhost:${process.env.E2E_PORT ?? 8123}`;
  const hq = await page.context().newPage();
  await hq.goto(`${admin}/sign-in/password`);
  await hq.getByLabel('Email or mobile number').fill(`trust-${testInfo.project.name}@wurk.co.za`);
  await hq.getByLabel(/^Password/).fill('sunny days in durban');
  await hq.getByRole('button', { name: 'Sign in' }).click();
  const codeBox = hq.getByRole('group', { name: 'One-time code' }).getByRole('textbox').first();
  await codeBox.fill(totp(SECRET));
  const signedIn = await hq.waitForURL(/\/dashboard$/, { timeout: 5000 }).then(
    () => true,
    () => false,
  );
  if (!signedIn) {
    await hq.waitForTimeout(30_000 - (Date.now() % 30_000) + 1000);
    await codeBox.fill(totp(SECRET));
  }
  await expect(hq).toHaveURL(/\/dashboard$/);
  await hq.goto(`${admin}/verification`);
  await hq.getByRole('link', { name }).click();
  await expect(hq.getByRole('button', { name: 'Approve identity' })).toBeVisible();
  await expectAccessible(hq);
  await hq.getByRole('button', { name: 'Approve identity' }).click();
  await expect(hq.getByText('Approved.')).toBeVisible();

  await page.reload();
  await expect(page.getByText('Verified by WURK', { exact: true })).toBeVisible();
  const port = new URL(page.url()).port;
  const badge = await page.context().newPage();
  await badge.goto(`http://wurk.test:${port}/verified/${handle}`);
  await expect(badge.getByText(`${name} is verified by WURK`)).toBeVisible();
  await expectAccessible(badge);
});
