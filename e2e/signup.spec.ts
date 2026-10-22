import { readFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

async function expectAccessible(page: Page) {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();
  const serious = results.violations.filter(
    (v) => v.impact === 'serious' || v.impact === 'critical',
  );
  expect(
    serious.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`),
  ).toEqual([]);
}

/** Without an SMS provider, e2e writes messages to the log; read the newest code for this number. */
function latestCode(e164: string): string {
  const log = readFileSync('storage/logs/laravel.log', 'utf8');
  const matches = [
    ...log.matchAll(new RegExp(`\\[sms → \\${e164}\\] Your WURK code is (\\d{6})`, 'g')),
  ];
  const code = matches.at(-1)?.[1];
  if (!code) throw new Error(`No code logged for ${e164}`);
  return code;
}

test('a new owner signs up from the pricing page and lands in their back office', async ({
  page,
}, testInfo) => {
  const digits = String(Date.now()).slice(-7);
  const local = `082${digits}`;
  const e164 = `+2782${digits}`;
  const handle = `e2e-${testInfo.project.name}-${digits}`;

  await page.goto(`http://wurk.test:${process.env.E2E_PORT ?? 8123}/pricing`);
  // The marketing site links to the app host; in e2e that is this same server on 127.0.0.1.
  const href = await page
    .getByRole('link', { name: 'Try free for 14 days' })
    .first()
    .getAttribute('href');
  await page.goto(`/signup${href?.slice(href.indexOf('?')) ?? ''}`);

  await expect(
    page.getByRole('heading', { level: 1, name: 'Tell us about your business' }),
  ).toBeVisible();
  await expectAccessible(page);
  await page.getByLabel(/^Business name/).fill(`E2E Studio ${digits}`);
  await page.getByLabel(/^First name/).fill('Ayanda');
  await page.getByLabel(/^Last name/).fill('Khumalo');
  await page.getByLabel(/^Email or mobile number/).fill(local);
  await page.getByRole('button', { name: 'Send my code' }).click();

  await expect(page.getByRole('heading', { level: 1, name: 'Check it’s you' })).toBeVisible();
  await expectAccessible(page);
  await page
    .getByRole('group', { name: 'One-time code' })
    .getByRole('textbox')
    .first()
    .fill(latestCode(e164));

  await expect(page.getByRole('heading', { level: 1, name: 'Choose a package' })).toBeVisible();
  await expectAccessible(page);
  await page.getByRole('radio', { name: /Starter/ }).click();
  await page.getByRole('button', { name: 'Continue' }).click();

  await expect(
    page.getByRole('heading', { level: 1, name: 'Pick your web address' }),
  ).toBeVisible();
  await page.getByLabel(/^Your web address/).fill(handle);
  await expect(page.getByLabel('Available')).toBeVisible();
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Continue' }).click();

  await expect(page.getByRole('heading', { level: 1, name: 'Ready to go' })).toBeVisible();
  await expect(page.getByText('14-day free trial starts now')).toBeVisible();
  await page.getByRole('button', { name: 'Create my business' }).click();

  await expect(page).toHaveURL(new RegExp(`/${handle}\\?welcome=1$`));
  await expect(page.getByRole('heading', { level: 1, name: `E2E Studio ${digits}` })).toBeVisible();

  // Billing: their trial, the price incl. VAT, and where to add a card.
  await page.goto(`/${handle}/settings/billing`);
  await expect(page.getByRole('heading', { level: 1, name: 'Billing' })).toBeVisible();
  await expect(page.getByText('R343.85 a month incl. VAT')).toBeVisible();
  await expect(page.getByText('Free trial')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Debit order' })).toBeVisible();
  await expectAccessible(page);
});
