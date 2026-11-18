import { expect, test } from './fixtures';
import { expectAccessible, latestCode, signUpBusiness } from './support';

/*
 * Sprint 23: easy setup and money tools, as people use them in the browser. WURK AI answers come from the simulated
 * provider in e2e; Google listings, payment providers and WhatsApp are covered by feature tests against recorded
 * responses.
 */

test('an owner sets up by talking, shares a poster, invoices a customer and marks it paid', async ({
  page,
}, testInfo) => {
  test.setTimeout(120_000);
  const handle = await signUpBusiness(page, testInfo, 'money');

  // Set up by talking (23.1): six questions, then one confirmed set-up.
  await page.goto(`/${handle}/website/set-up`);
  await expect(page.getByRole('heading', { level: 1, name: 'Set up by talking' })).toBeVisible();
  await expect(
    page.getByRole('form', { name: 'Start from what you already have online' }),
  ).toBeVisible();
  await expectAccessible(page);
  for (let i = 0; i < 6; i++) {
    await expect(page.getByText(`Question ${i + 1} of 6`)).toBeVisible();
    const answer = page.getByRole('form', { name: /^Answer: / });
    await answer.getByRole('textbox').fill(`Answer ${i + 1}`);
    await answer.getByRole('button', { name: 'Next', exact: true }).click();
  }
  await expect(page.getByText('This is exactly what will happen:')).toBeVisible();
  await page.getByRole('button', { name: 'Set it up' }).click();
  await expect(page.getByText(/^All set up:/)).toBeVisible();

  // The share kit (23.3): links and a poster.
  await page.goto(`/${handle}/website/share`);
  await expect(page.getByRole('heading', { level: 1, name: 'Share' })).toBeVisible();
  await expectAccessible(page);

  // Who owes me (23.5a): an invoice with no number or email gets a link to share, then is paid in cash.
  await page.goto(`/${handle}/money/owed`);
  await expect(page.getByRole('heading', { level: 1, name: 'Who owes me' })).toBeVisible();
  await page.getByLabel('Customer’s name').fill('Lerato Dlamini');
  await page.getByLabel('Line 1: description').fill('Wedding hair, 3 people');
  await page.getByLabel('Line 1: price each (R)').fill('400');
  await page.getByRole('button', { name: 'Save and send' }).click();
  await expect(
    page.getByText(
      'INV-0001 is ready: there’s no number or email to send it to, so share its link.',
    ),
  ).toBeVisible();
  await expect(page.getByRole('heading', { name: /R400\.00 owed by 1 customer/ })).toBeVisible();
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Mark paid: INV-0001' }).click();
  await page.getByRole('button', { name: 'Cash: INV-0001' }).click();
  await expect(page.getByText('INV-0001 marked paid.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Nobody owes you anything' })).toBeVisible();
});

test('an owner cashes up with a difference and adds an expense', async ({ page }, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'cashup');

  await page.goto(`/${handle}/money/cash-up`);
  await expect(page.getByRole('heading', { level: 1, name: 'Cash-up' })).toBeVisible();
  await expectAccessible(page);
  await page.getByLabel('Cash counted (R)').fill('50');
  await expect(
    page.getByText('The cash is R50.00 over. Say why before locking the day.'),
  ).toBeVisible();
  await page.getByRole('button', { name: 'Lock the day' }).click();
  await expect(
    page.getByText('The cash is R50.00 over. Say why before locking the day.').last(),
  ).toBeVisible();
  await page.getByLabel('Why it’s different (if it is)').fill('Float from yesterday');
  await page.getByRole('button', { name: 'Lock the day' }).click();
  await expect(page.getByText('Cashed up and locked: R50.00 over.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Reopen this day' })).toBeVisible();

  await page.goto(`/${handle}/money/expenses`);
  await expect(page.getByRole('heading', { level: 1, name: 'Expenses and profit' })).toBeVisible();
  await page.getByLabel('Amount (R)').fill('120');
  await page.getByLabel('Category').selectOption('transport');
  await page.getByLabel('Note').fill('Taxi to the supplier');
  await page.getByRole('button', { name: 'Add the expense' }).click();
  await expect(page.getByText('R120.00 added to transport.')).toBeVisible();
  await expect(page.getByText(/spent R120\.00/)).toBeVisible();
  await expectAccessible(page);
});

test('an agent sets a business up on their phone and hands it over', async ({ page }, testInfo) => {
  test.setTimeout(90_000);
  const digits = String(Date.now()).slice(-7);
  await page.goto('/sign-in/password');
  await page
    .getByLabel('Email or mobile number')
    .fill(`onboard-${testInfo.project.name}@wurk.co.za`);
  await page.getByLabel(/^Password/).fill('sunny days in durban');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).not.toHaveURL(/sign-in/);

  await page.goto('/agent/onboard');
  await expect(page.getByRole('heading', { level: 1, name: 'Set up a business' })).toBeVisible();
  await expectAccessible(page);
  await page.getByLabel('Business name').fill(`Agent Studio ${digits}`);
  await page.getByLabel('Owner’s first name').fill('Nomsa');
  await page.getByLabel('Owner’s last name').fill('Khumalo');
  await page.getByLabel('Owner’s mobile number').fill(`083${digits}`);
  await page.getByRole('button', { name: 'Send the code' }).click();
  await expect(page.getByText(/Ask Nomsa for the code we sent/)).toBeVisible();
  await page.getByLabel('Code', { exact: true }).fill(latestCode(`+2783${digits}`));
  await page.getByRole('button', { name: 'Check the code' }).click();
  await expect(page.getByRole('group', { name: 'Package' })).toBeVisible();
  await page.getByRole('button', { name: /^Create Agent Studio/ }).click();
  await expect(page.getByRole('link', { name: 'Set up by talking' })).toBeVisible();
  await page.getByRole('button', { name: 'Next: the first top-up' }).click();
  await page.getByRole('button', { name: 'The owner will top up later' }).click();
  await page.getByRole('button', { name: 'Hand over to Nomsa' }).click();
  await expect(page.getByText(/^Handed over in \d+ minutes?\./)).toBeVisible();
  await expectAccessible(page);
});
