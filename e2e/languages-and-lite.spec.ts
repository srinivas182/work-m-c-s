import { expect, test } from './fixtures';
import { expectAccessible, signUpBusiness } from './support';

/*
 * Sprint 24: languages, lite mode and working offline, as people use them. Translations themselves are drafted and
 * reviewed in production; here the language switch, customer pages, lite mode and the offline outbox are checked.
 */

test('an owner picks their language, and lite mode follows Data Saver', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'lang');
  await page.goto('/account/security');
  await expect(page.getByRole('heading', { level: 1, name: 'Account & security' })).toBeVisible();
  await page.getByLabel('The language WURK uses for you').selectOption('zu');
  await expect(page.locator('html')).toHaveAttribute('lang', 'zu');
  await expectAccessible(page);
  await page.getByLabel('The language WURK uses for you').selectOption('en');
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');

  await page.getByLabel('On this phone').selectOption('on');
  await expect(page.locator('html')).toHaveAttribute('data-lite', '1');
  await expect(page.getByText('Lite mode is on now.')).toBeVisible();
  await page.getByLabel('On this phone').selectOption('auto');
  await expect(page.locator('html')).not.toHaveAttribute('data-lite', '1');
  await page.goto(`/${handle}`);
});

test('an expense recorded with no signal waits, then is sent once', async ({
  page,
  context,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'offline');
  await page.goto(`/${handle}/money/expenses`);
  await expect(page.getByRole('heading', { level: 1, name: 'Expenses and profit' })).toBeVisible();

  await context.setOffline(true);
  await page.getByLabel('Amount (R)').fill('35');
  await page.getByLabel('Category').selectOption('transport');
  await page.getByLabel('Note').fill('Taxi, no signal');
  await page.getByRole('button', { name: 'Add the expense' }).click();
  await expect(page.getByText(/You’re offline/)).toBeVisible();
  await expect(page.getByText(/1 waiting to send: Expense/)).toBeVisible();

  await context.setOffline(false);
  await expect(page.getByText(/waiting to send/)).toHaveCount(0, { timeout: 15_000 });
  await page.reload();
  await expect(page.getByText(/Taxi, no signal/)).toHaveCount(1);
});
