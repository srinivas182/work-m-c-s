import { readFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, type TestInfo } from '@playwright/test';

/** No serious or critical WCAG 2.2 AA issues on the current page. */
export async function expectAccessible(page: Page) {
  // Judge the settled page: wait for running animations and transitions (a toast fading in,
  // a colour change) — mid-animation, colours are briefly paler than anyone actually reads them.
  await page.evaluate(() =>
    Promise.all(
      document
        .getAnimations()
        .filter((a) => a.effect?.getComputedTiming().iterations !== Infinity)
        .map((a) => a.finished.catch(() => undefined)),
    ),
  );
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
export function latestCode(e164: string): string {
  const log = readFileSync('storage/logs/laravel.log', 'utf8');
  const matches = [
    ...log.matchAll(new RegExp(`\\[sms → \\${e164}\\] Your WURK code is (\\d{6})`, 'g')),
  ];
  const code = matches.at(-1)?.[1];
  if (!code) throw new Error(`No code logged for ${e164}`);
  return code;
}

/** Signs up a new business on the Starter trial and returns its handle, signed in as its owner. */
export async function signUpBusiness(
  page: Page,
  testInfo: TestInfo,
  prefix: string,
  choice: { category: string; design: string } = {
    category: 'Salon & beauty',
    design: 'Warm salon (sample)',
  },
  pkg = 'starter', // the package to sign up on (Growth and Pro include the shop)
): Promise<string> {
  const digits = String(Date.now()).slice(-7);
  const e164 = `+2782${digits}`;
  const handle = `${prefix}-${testInfo.project.name}-${digits}`;

  await page.goto(`/signup?package=${pkg}`);
  await page.getByLabel(/^Business name/).fill(`E2E Studio ${digits}`); // never a seeded name (e.g. "Glow Salon")
  await page.getByLabel(/^First name/).fill('Thandi');
  await page.getByLabel(/^Last name/).fill('Mokoena');
  await page.getByLabel(/^Email or mobile number/).fill(`082${digits}`);
  await page.getByRole('button', { name: 'Send my code' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Check it’s you' })).toBeVisible();
  await page
    .getByRole('group', { name: 'One-time code' })
    .getByRole('textbox')
    .first()
    .fill(latestCode(e164));
  await expect(page.getByRole('heading', { level: 1, name: 'Choose a package' })).toBeVisible();
  await page.getByRole('button', { name: 'Continue' }).click();

  // Category and design (shown once designs are published — the e2e setup publishes samples).
  await expect(
    page.getByRole('heading', { level: 1, name: 'What kind of business is it?' }),
  ).toBeVisible();
  await page.getByRole('radio', { name: new RegExp(choice.category) }).click();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(
    page.getByRole('heading', { level: 1, name: 'Choose your website design' }),
  ).toBeVisible();
  await page
    .getByRole('radio', { name: new RegExp(choice.design.replace(/[()]/g, '\\$&')) })
    .click();
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByLabel(/^Your web address/).fill(handle);
  await expect(page.getByLabel('Available')).toBeVisible();
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByRole('button', { name: 'Create my business' }).click();
  await expect(page).toHaveURL(new RegExp(`/${handle}\\?welcome=1$`));

  return handle;
}
