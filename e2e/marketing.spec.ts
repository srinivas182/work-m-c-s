import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const site = `http://wurk.test:${process.env.E2E_PORT ?? 8123}`;

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

test('the marketing site is accessible and shows live prices', async ({ page }) => {
  await page.goto(`${site}/`);
  await expect(
    page.getByRole('heading', {
      level: 1,
      name: 'Your business online and taking bookings by tonight.',
    }),
  ).toBeVisible();
  await expectAccessible(page);

  await page.goto(`${site}/pricing`);
  const starter = page.getByRole('region', { name: 'Starter' });
  await expect(starter.getByText('R299', { exact: true })).toBeVisible();
  await expect(starter.getByText('R343.85 incl. VAT')).toBeVisible();
  await page.getByRole('radio', { name: 'Yearly' }).click();
  await expect(starter.getByText('R2 990', { exact: true })).toBeVisible();
  await expectAccessible(page);

  for (const path of ['/faq', '/contact', '/legal/terms']) {
    await page.goto(`${site}${path}`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expectAccessible(page);
  }
});
