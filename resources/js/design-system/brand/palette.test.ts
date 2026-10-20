import { describe, expect, it } from 'vitest';
import { AA_NON_TEXT, AA_TEXT, AAA_TEXT, contrast } from './contrast';
import { hexToOklch, isHex, oklchToHex } from './oklch';
import { generateNeutral, generatePalette, type ModePalette } from './palette';

const EDGE_CASES: Record<string, string> = {
  'WURK violet': '#6d4aff',
  'pure yellow': '#ffff00',
  'near white': '#fafafa',
  'pure white': '#ffffff',
  'near black': '#0a0a0a',
  'pure black': '#000000',
  'mid grey': '#808080',
  'neon green': '#39ff14',
  'neon pink': '#ff10f0',
  'pure red': '#ff0000',
  'pure blue': '#0000ff',
  cyan: '#00ffff',
  'salon gold': '#c9a227',
  'dental teal': '#0f9d9a',
  'low-saturation sage': '#9caf88',
  orange: '#ff7a00',
};

function expectAccessible(mode: ModePalette) {
  const [s1, s2, s3] = mode.scale;
  for (const bg of [s1, s2, s3]) {
    expect(contrast(mode.scale[10], bg)).toBeGreaterThanOrEqual(AA_TEXT);
    expect(contrast(mode.scale[11], bg)).toBeGreaterThanOrEqual(AAA_TEXT);
    expect(contrast(mode.scale[7], bg)).toBeGreaterThanOrEqual(AA_NON_TEXT);
  }
  expect(contrast(mode.onSolid, mode.scale[8])).toBeGreaterThanOrEqual(AA_TEXT);
  expect(contrast(mode.onSolid, mode.scale[9])).toBeGreaterThanOrEqual(AA_TEXT);
}

/** Deterministic pseudo-random generator so failures are reproducible. */
function seeded(seed: number) {
  return () => {
    seed = (seed * 1664525 + 1013904223) % 4294967296;
    return seed / 4294967296;
  };
}

describe('oklch conversions', () => {
  it('round-trips hex through OKLCH', () => {
    for (const hex of Object.values(EDGE_CASES)) {
      expect(oklchToHex(hexToOklch(hex))).toBe(hex);
    }
  });

  it('accepts short hex and rejects invalid input', () => {
    expect(isHex('#abc')).toBe(true);
    expect(isHex('purple')).toBe(false);
    expect(() => hexToOklch('nope')).toThrow();
  });
});

describe('generatePalette', () => {
  for (const [name, hex] of Object.entries(EDGE_CASES)) {
    it(`produces accessible light and dark scales for ${name} (${hex})`, () => {
      const palette = generatePalette(hex);
      expect(palette.light.scale).toHaveLength(12);
      palette.light.scale.forEach((c) => expect(isHex(c)).toBe(true));
      palette.dark.scale.forEach((c) => expect(isHex(c)).toBe(true));
      expectAccessible(palette.light);
      expectAccessible(palette.dark);
    });
  }

  it('keeps an ordinary brand colour as the solid fill unchanged', () => {
    expect(generatePalette('#6d4aff').light.scale[8]).toBe('#6d4aff');
  });

  it('reports adjustments when it has to fix a colour', () => {
    expect(generatePalette('#ffff00').adjustments.length).toBeGreaterThan(0);
  });

  it('orders background-to-text steps from light to dark in light mode', () => {
    const l = generatePalette('#0f9d9a').light.scale.map((c) => hexToOklch(c).l);
    for (const i of [0, 1, 2, 3, 4, 5, 6]) {
      expect(l[i]!).toBeGreaterThan(l[i + 1]!);
    }
    expect(l[10]!).toBeGreaterThan(l[11]!);
  });

  it('orders background-to-text steps from dark to light in dark mode', () => {
    const l = generatePalette('#0f9d9a').dark.scale.map((c) => hexToOklch(c).l);
    for (const i of [0, 1, 2, 3, 4, 5, 6]) {
      expect(l[i]!).toBeLessThan(l[i + 1]!);
    }
    expect(l[10]!).toBeLessThan(l[11]!);
  });

  it('stays accessible for 300 random brand colours', () => {
    const random = seeded(42);
    for (let i = 0; i < 300; i++) {
      const hex = `#${Math.floor(random() * 0xffffff)
        .toString(16)
        .padStart(6, '0')}`;
      const palette = generatePalette(hex);
      expectAccessible(palette.light);
      expectAccessible(palette.dark);
    }
  });
});

describe('generateNeutral', () => {
  it('produces low-chroma greys tinted toward the brand hue', () => {
    const neutral = generateNeutral('#6d4aff');
    for (const c of neutral.light.scale) {
      expect(hexToOklch(c).c).toBeLessThan(0.03);
    }
    expectAccessible(neutral.light);
    expectAccessible(neutral.dark);
  });
});
