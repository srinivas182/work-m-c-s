import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { contrast } from '@/design-system/brand/contrast';
import { FONT_PAIRINGS, STYLES, siteTheme } from './theme';

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

describe('styles (Sprint 20.1)', () => {
  const vars = (
    style: 'luxury' | 'minimal' | 'bold',
    primary = '#9D174D',
    secondary: string | null = '#D97706',
  ) =>
    siteTheme({
      primary,
      secondary,
      font_pairing: STYLES[style].font_pairing,
      corners: STYLES[style].corners,
      style,
      logo: null,
      favicon: null,
    }) as Record<string, string>;

  it('gives each style its own type, spacing and buttons, and its pairing has fallbacks', () => {
    expect(
      new Set((['luxury', 'minimal', 'bold'] as const).map((s) => vars(s)['--s-heading-weight'])),
    ).toHaveProperty('size', 3);
    for (const style of ['luxury', 'minimal', 'bold'] as const) {
      expect(FONT_PAIRINGS[STYLES[style].font_pairing], style).toBeDefined();
      expect(vars(style)['--s-section-y']).toMatch(/^clamp\(/);
    }
    expect(vars('luxury')['--s-btn-case']).toBe('uppercase');
  });

  it.each(['#9D174D', '#FFEB3B', '#00BFA5', '#111111', '#E53935', '#9E9E9E', '#6D4AFF', '#0D47A1'])(
    'keeps text readable in Luxury’s deep bands with %s',
    (primary) => {
      const v = vars('luxury', primary, '#F59E0B') as Record<string, string>;
      const c = (a: string, b: string) => contrast(v[a] ?? '', v[b] ?? '');
      expect(c('--s-inv-fg', '--s-inv-bg')).toBeGreaterThanOrEqual(4.5);
      expect(c('--s-inv-fg-muted', '--s-inv-bg')).toBeGreaterThanOrEqual(4.5);
      expect(c('--s-inv-brand-text', '--s-inv-bg')).toBeGreaterThanOrEqual(4.5);
      expect(c('--s-inv-on-brand', '--s-inv-brand')).toBeGreaterThanOrEqual(4.5);
    },
  );
});
