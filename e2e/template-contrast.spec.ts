import { readdirSync, readFileSync } from 'node:fs';
import { expect, test } from './fixtures';
import { accessibilityProblems } from './support';

/*
 * Sprint 18.5b — all 24 website templates, every page, pass WCAG 2.2 AA (contrast included) in their own colours and
 * (20.1) in each of their three styles;
 * and their home pages in a spread of colours a business might choose: the palette engine guarantees readable
 * pairings for any colour (palette.test.ts), this checks the templates actually use them. Desktop; part of the
 * accessibility crawl in CI.
 */

const COLOURS: Record<string, string>[] = [
  { primary: '#FFEB3B', secondary: '#0D47A1' }, // a very light yellow brand with a deep blue
  { primary: '#E53935' }, // bright red
  { primary: '#9E9E9E' }, // mid grey
  { primary: '#111111', secondary: '#FFFFFF' }, // near black, white accent
  { primary: '#00BFA5', secondary: '#FF6F00' }, // teal and orange
  { primary: '#6D4AFF', secondary: '#F59E0B' }, // WURK purple and amber
];

const templates = readdirSync('modules/Website/templates')
  .filter((f) => f.endsWith('.json'))
  .map(
    (f) =>
      JSON.parse(readFileSync(`modules/Website/templates/${f}`, 'utf8')) as {
        key: string;
        definition: { pages: { key: string; is_home: boolean }[] };
      },
  );

test('all 24 templates are checked @screens', () => {
  expect(templates.length).toBe(24);
});

for (const [i, template] of templates.entries()) {
  test(`@screens template ${template.key}: every page, and the home page in ${COLOURS.length} colours`, async ({
    page,
  }, testInfo) => {
    test.skip(
      testInfo.project.name !== 'desktop',
      'Colours don’t depend on the screen size; desktop only.',
    );
    test.setTimeout(240_000);
    // Previews are limited to 120 a minute per visitor; each template counts as its own visitor (the e2e environment's
    // load-test token, as in load/k6), so 24 templates checked in parallel aren't throttled.
    await page.setExtraHTTPHeaders({
      'X-Wurk-Load-Test': 'e2e-visitors',
      'X-Wurk-Load-Visitor': `10.18.5.${i + 1}`,
    });
    const base = `/templates/${template.key}/preview`;
    const visits = [
      ...template.definition.pages.map((p) => ({ label: p.key, url: `${base}?page=${p.key}` })),
      // Sprint 20.1: every page in the other two styles too (Luxury's deep bands, Bold's colour washes).
      ...(['luxury', 'bold'] as const).flatMap((style) =>
        template.definition.pages.map((p) => ({
          label: `${p.key} in ${style}`,
          url: `${base}?page=${p.key}&style=${style}`,
        })),
      ),
      ...COLOURS.map((c) => ({
        label: `home in ${c.primary}${c.secondary ? `/${c.secondary}` : ''}`,
        url: `${base}?${new URLSearchParams(c).toString()}`,
      })),
    ];
    const problems: string[] = [];
    for (const v of visits) {
      const r = await page.goto(v.url);
      if ((r?.status() ?? 0) >= 400) {
        problems.push(`${v.label}: HTTP ${r?.status()}`);
        continue;
      }
      await page.waitForLoadState('networkidle').catch(() => undefined);
      for (const p of await accessibilityProblems(page)) problems.push(`${v.label}: ${p}`);
    }
    expect(problems).toEqual([]);
  });
}
