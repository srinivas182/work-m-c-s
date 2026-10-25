import { expect, test } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import { expectAccessible } from './support';

/**
 * Every public page of every template, as visitors get it (static HTML, islands, the page head),
 * on desktop and phone — from the demo sites published by `website:demo-sites` (scripts/e2e-setup).
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
  test.describe(`${template.key} (public)`, () => {
    for (const key of [
      ...template.definition.pages.map((p) => p.key),
      'privacy',
      'terms',
      'cookies',
    ]) {
      test(`“${key}” is accessible`, async ({ page }, testInfo) => {
        const port = new URL(String(testInfo.project.use.baseURL)).port;
        await page.goto(
          `http://demo-${template.key}.wurk.test:${port}${key === 'home' ? '/' : `/${key}`}`,
        );
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expectAccessible(page);
      });
    }
  });
}
