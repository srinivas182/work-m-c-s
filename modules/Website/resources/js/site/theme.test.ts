import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { FONT_PAIRINGS } from './theme';

const css = readFileSync('modules/Website/resources/js/site/font-fallbacks.css', 'utf8');
const defined = new Set([...css.matchAll(/font-family: '([^']+)'/g)].map((m) => m[1]));

describe('font fallbacks (no layout shift when a web font arrives late)', () => {
  it.each(
    Object.entries(FONT_PAIRINGS).flatMap(([name, p]) => [
      [`${name} display`, p.display],
      [`${name} body`, p.body],
    ]),
  )('%s names a size-matched fallback that exists', (_, stack) => {
    const families = stack.split(',').map((f) => f.trim().replace(/^'|'$/g, ''));
    const fallback = families.find((f) => f.endsWith(' Fallback'));
    expect(fallback, stack).toBeDefined();
    expect(defined.has(fallback!), `${fallback} is not in font-fallbacks.css`).toBe(true);
    // Right after the web font, before any generic family.
    const at = families.indexOf(fallback!);
    expect(
      families.slice(0, at).every((f) => f.startsWith(fallback!.replace(' Fallback', ''))),
      stack,
    ).toBe(true);
  });
});
