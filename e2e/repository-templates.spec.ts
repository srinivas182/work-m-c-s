import { expect, test } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import { expectAccessible } from './support';

/**
 * The preview of every repository template's home page is accessible (the preview's own controls).
 * Every page, as visitors get it, is scanned by public-templates.spec.ts.
 */
const templates = readdirSync('modules/Website/templates')
  .filter((f) => f.endsWith('.json'))
  .map(
    (f) =>
      JSON.parse(readFileSync(`modules/Website/templates/${f}`, 'utf8')) as {
        key: string;
        definition: { pages: { key: string }[] };
      },
  );

for (const template of templates) {
  test.describe(template.key, () => {
    for (const key of ['home']) {
      test(`“${key}” is accessible`, async ({ page }) => {
        await page.goto(`/templates/${template.key}/preview?page=${key}`);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expectAccessible(page);
      });
    }
  });
}
