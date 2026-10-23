import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// The block design board: every block, two very different brands, accessibility-scanned.
for (const business of ['salon', 'dental']) {
  test(`blocks are accessible for the ${business} sample`, async ({ page }) => {
    await page.goto(`/_blocks?business=${business}`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2 }).first()).toBeVisible();
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
      .analyze();
    const serious = results.violations.filter(
      (v) => v.impact === 'serious' || v.impact === 'critical',
    );
    expect(
      serious.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`),
    ).toEqual([]);
  });
}
