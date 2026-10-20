import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

async function expectNoSeriousA11yIssues(page: Page) {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();
  const serious = results.violations.filter(
    (v) => v.impact === 'serious' || v.impact === 'critical',
  );
  expect(
    serious.map((v) => `${v.id}: ${v.help} (${v.nodes.map((n) => n.target.join(' ')).join(', ')})`),
  ).toEqual([]);
}

for (const theme of ['light', 'dark'] as const) {
  test(`design showcase renders accessibly in ${theme} mode`, async ({ page }) => {
    await page.addInitScript((t) => window.localStorage.setItem('wurk-theme', t), theme);
    await page.goto('/_design');

    await expect(page.getByRole('heading', { level: 1, name: 'WURK design system' })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
    await expectNoSeriousA11yIssues(page);

    for (const tab of ['Forms', 'Data', 'Feedback', 'Overlays', 'Typography']) {
      await page.getByRole('tab', { name: tab }).click();
      await expectNoSeriousA11yIssues(page);
    }
  });
}

test('brand colour re-themes the page', async ({ page }) => {
  await page.goto('/_design');
  const brand9 = () =>
    page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('--wurk-brand-9').trim(),
    );
  const before = await brand9();

  const input = page.getByTestId('brand-hex');
  await input.fill('#0f9d9a');

  await expect.poll(brand9).toBe('#0f9d9a');
  expect(before).not.toBe('#0f9d9a');
  await expectNoSeriousA11yIssues(page);
});

test('a hard-to-read brand colour is corrected and stays accessible', async ({ page }) => {
  await page.goto('/_design');
  await page.getByTestId('brand-hex').fill('#ffff00');
  await expect(page.getByText(/to reach (3|4\.5|7):1|text reaches 4\.5:1/).first()).toBeVisible();
  await expectNoSeriousA11yIssues(page);
});

test('theme menu switches to dark and persists', async ({ page }) => {
  await page.goto('/_design');
  await page.getByRole('button', { name: 'Change theme' }).click();
  await page.getByRole('menuitem', { name: 'Dark' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
});

test('navigation adapts to the viewport', async ({ page, isMobile }) => {
  await page.goto('/_design');
  if (isMobile) {
    const quickNav = page.getByRole('navigation', { name: 'Quick' });
    await expect(quickNav, 'bottom navigation is visible on mobile').toBeVisible({ timeout: 5000 });
    await quickNav.getByRole('button', { name: 'More' }).click({ timeout: 5000 });
    const sheet = page.getByRole('dialog', { name: 'Menu' });
    await expect(sheet, 'menu sheet opens').toBeVisible({ timeout: 5000 });
    await expect(sheet.getByRole('link', { name: 'Design system' })).toBeVisible({ timeout: 5000 });
  } else {
    await expect(
      page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Design system' }),
    ).toHaveAttribute('aria-current', 'page');
    await page.getByRole('button', { name: 'Collapse sidebar' }).click();
    await expect(page.getByRole('button', { name: 'Expand sidebar' })).toBeVisible();
  }
});

test('welcome page renders', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { level: 1, name: 'WURK' })).toBeVisible();
  await expectNoSeriousA11yIssues(page);
});
