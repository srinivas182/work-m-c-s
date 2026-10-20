import { AA_NON_TEXT, AA_TEXT, AAA_TEXT, contrast } from './contrast';
import { hexToOklch, oklchToHex, type Oklch } from './oklch';

/**
 * Brand palette engine.
 *
 * From one brand colour we generate a 12-step tonal scale for light and dark mode,
 * following a fixed role for each step (the same convention across every scale):
 *
 *   1–2   app and card backgrounds
 *   3–5   component backgrounds (normal / hover / pressed)
 *   6–8   borders and focus rings (8 = strongest, used for focus)
 *   9–10  solid fills (9 = the brand colour, 10 = hover)
 *   11    low-emphasis text and icons
 *   12    high-emphasis text
 *
 * The contrast guard then nudges lightness until every pairing the components rely
 * on passes WCAG 2.2 AA — so a tenant can pick any colour and the result stays readable.
 */

export type Scale = [
  string,
  string,
  string,
  string,
  string,
  string,
  string,
  string,
  string,
  string,
  string,
  string,
];

export type Mode = 'light' | 'dark';

export interface ModePalette {
  scale: Scale;
  /** Text colour to place on step 9 / 10 (solid brand fills). */
  onSolid: string;
}

export interface Palette {
  source: string;
  light: ModePalette;
  dark: ModePalette;
  /** Human-readable notes on what the contrast guard changed. */
  adjustments: string[];
}

export interface PaletteOptions {
  /**
   * Scale chroma down (0–1). Neutrals use a small value so greys are subtly
   * tinted toward the brand hue instead of flat.
   */
  chromaScale?: number;
  /** Keep the source colour as step 9 (true for brand, false for neutrals). */
  anchorSolid?: boolean;
}

const LIGHT_L = [0.993, 0.982, 0.962, 0.937, 0.909, 0.876, 0.83, 0.764, 0, 0, 0.49, 0.27];
const DARK_L = [0.165, 0.196, 0.245, 0.285, 0.325, 0.37, 0.43, 0.52, 0, 0, 0.82, 0.95];
const CHROMA_CURVE = [0.08, 0.14, 0.28, 0.4, 0.5, 0.58, 0.68, 0.82, 1, 1, 0.9, 0.5];

const WHITE = '#ffffff';
const INK = '#0c0a14';

const round = (n: number) => Math.round(n * 1000) / 1000;
const capitalise = (mode: Mode) => (mode === 'light' ? 'Light' : 'Dark');
const changeVerb = (delta: number) => (delta < 0 ? 'darkened' : 'lightened');

function buildScale(base: Oklch, mode: Mode, options: Required<PaletteOptions>): Oklch[] {
  const targets = mode === 'light' ? LIGHT_L : DARK_L;
  const chroma = base.c * options.chromaScale;

  // Step 9 keeps the brand's own lightness (clamped so it can act as a solid fill
  // in both modes); step 10 is its hover state.
  const solidL = options.anchorSolid
    ? Math.min(Math.max(base.l, mode === 'light' ? 0.25 : 0.45), mode === 'light' ? 0.92 : 0.94)
    : mode === 'light'
      ? 0.6
      : 0.62;
  const hoverL = solidL > 0.6 ? solidL - 0.05 : solidL + (mode === 'light' ? -0.05 : 0.05);

  return targets.map((l, i) => {
    const lightness = i === 8 ? solidL : i === 9 ? hoverL : l;
    const c = i >= 8 && i <= 9 && options.anchorSolid ? base.c : chroma * (CHROMA_CURVE[i] ?? 1);
    return { l: lightness, c, h: base.h };
  });
}

/** Moves lightness one direction until `pass` holds (or the range is exhausted). */
function nudge(color: Oklch, direction: -1 | 1, pass: (hex: string) => boolean): Oklch {
  let current = color;
  for (let i = 0; i < 200 && !pass(oklchToHex(current)); i++) {
    const l = current.l + direction * 0.005;
    if (l < 0 || l > 1) break;
    current = { ...current, l };
  }
  return current;
}

function guard(scale: Oklch[], mode: Mode, notes: string[]): ModePalette {
  const hex = () => scale.map(oklchToHex);
  const backgrounds = () => [0, 1, 2].map((i) => hex()[i] as string);
  const minContrast = (fg: string) => Math.min(...backgrounds().map((bg) => contrast(fg, bg)));
  const towardsContrast: -1 | 1 = mode === 'light' ? -1 : 1;

  const fix = (index: number, threshold: number, role: string) => {
    const before = scale[index] as Oklch;
    const after = nudge(before, towardsContrast, (c) => minContrast(c) >= threshold);
    if (after.l !== before.l) {
      notes.push(
        `${capitalise(mode)} mode: ${role} (step ${index + 1}) ${changeVerb(after.l - before.l)} ${round(before.l)} → ${round(after.l)} to reach ${threshold}:1`,
      );
      scale[index] = after;
    }
  };

  fix(10, AA_TEXT, 'muted text');
  fix(11, AAA_TEXT, 'text');
  fix(7, AA_NON_TEXT, 'focus ring');

  // Text on the solid fill: pick white or ink, whichever reads better; if neither
  // reaches AA (only possible for mid-tones), darken or lighten the fill until it does.
  const solid = () => hex()[8] as string;
  let onSolid = contrast(WHITE, solid()) >= contrast(INK, solid()) ? WHITE : INK;
  if (contrast(onSolid, solid()) < AA_TEXT) {
    const direction: -1 | 1 = onSolid === WHITE ? -1 : 1;
    const before = scale[8] as Oklch;
    scale[8] = nudge(before, direction, (c) => contrast(onSolid, c) >= AA_TEXT);
    notes.push(
      `${capitalise(mode)} mode: solid fill ${changeVerb((scale[8] as Oklch).l - before.l)} ${round(before.l)} → ${round((scale[8] as Oklch).l)} so ${onSolid === WHITE ? 'white' : 'dark'} text reaches 4.5:1`,
    );
    const s = scale[8] as Oklch;
    scale[9] = { ...s, l: s.l + (onSolid === WHITE ? -0.05 : 0.05) };
  }
  // Hover fill must keep the same text readable.
  scale[9] = nudge(
    scale[9] as Oklch,
    onSolid === WHITE ? -1 : 1,
    (c) => contrast(onSolid, c) >= AA_TEXT,
  );
  if (contrast(onSolid, solid()) < AA_TEXT) {
    onSolid = onSolid === WHITE ? INK : WHITE;
  }

  return { scale: hex() as Scale, onSolid };
}

export function generatePalette(source: string, options: PaletteOptions = {}): Palette {
  const resolved: Required<PaletteOptions> = {
    chromaScale: options.chromaScale ?? 1,
    anchorSolid: options.anchorSolid ?? true,
  };
  const base = hexToOklch(source);
  const adjustments: string[] = [];

  return {
    source,
    light: guard(buildScale(base, 'light', resolved), 'light', adjustments),
    dark: guard(buildScale(base, 'dark', resolved), 'dark', adjustments),
    adjustments,
  };
}

/** Neutral greys, subtly tinted toward the brand hue. */
export function generateNeutral(brand: string): Palette {
  const { h } = hexToOklch(brand);
  return generatePalette(oklchToHex({ l: 0.55, c: 0.02, h }), {
    chromaScale: 0.5,
    anchorSolid: false,
  });
}
