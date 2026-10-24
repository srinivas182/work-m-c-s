import { expect, test } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import { expectAccessible } from './support';

/** Every page of every repository template (plus its legal pages) is accessible — one test per page. */
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
    for (const key of [
      ...template.definition.pages.map((p) => p.key),
      'privacy',
      'terms',
      'cookies',
    ]) {
      test(`“${key}” is accessible`, async ({ page }) => {
        await page.goto(`/templates/${template.key}/preview?page=${key}`);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expectAccessible(page);
      });
    }
  });
}
