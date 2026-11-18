import { readdirSync, readFileSync } from 'node:fs';
import { expect, test } from './fixtures';

/*
 * Sprint 19.4a — nothing on any template is wider than a phone. Found when a long business name pushed the header's
 * "Book now" and menu off the screen: the phone then zooms the whole page out and taps land in the wrong place
 * (the chat button couldn't be tapped). Every page of every template's preview, and each published demo site's home
 * page (demo businesses have long names, like real ones often do). Phone only; part of the screens runs in CI.
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

for (const [i, template] of templates.entries()) {
  test(`@screens template ${template.key} fits a phone`, async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'Phone widths only.');
    test.setTimeout(90_000);
    await page.setExtraHTTPHeaders({
      'X-Wurk-Load-Test': 'e2e-visitors',
      'X-Wurk-Load-Visitor': `10.19.4.${i + 1}`,
    });
    const port = new URL(testInfo.project.use.baseURL ?? 'http://127.0.0.1:8123').port;
    const visits = [
      ...template.definition.pages.map((p) => `/templates/${template.key}/preview?page=${p.key}`),
      `http://demo-${template.key}.wurk.test:${port}/`,
    ];
    const tooWide: string[] = [];
    for (const url of visits) {
      await page.goto(url);
      const { page: width, screen } = await page.evaluate(() => ({
        page: Math.max(document.documentElement.scrollWidth, window.innerWidth),
        screen: window.screen.width,
      }));
      if (width > screen) tooWide.push(`${url}: ${width}px on a ${screen}px screen`);
    }
    expect(tooWide).toEqual([]);
  });
}
