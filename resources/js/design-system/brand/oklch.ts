/**
 * Colour-space conversions between sRGB hex and OKLCH.
 *
 * OKLCH is perceptually uniform: equal steps in lightness look like equal steps to
 * the eye, which is what lets us generate even tonal scales from one brand colour.
 * Matrices from Björn Ottosson, "A perceptual color space for image processing" (2020).
 */

export interface Oklch {
  /** Lightness, 0–1 */
  l: number;
  /** Chroma, 0 to ~0.4 */
  c: number;
  /** Hue in degrees, 0–360 */
  h: number;
}

export type Rgb = [r: number, g: number, b: number];

const HEX = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i;

export function isHex(value: string): boolean {
  return HEX.test(value.trim());
}

export function hexToRgb(hex: string): Rgb {
  const match = HEX.exec(hex.trim());
  if (!match?.[1]) {
    throw new Error(`Invalid hex colour "${hex}"`);
  }
  let digits = match[1];
  if (digits.length === 3) {
    digits = digits
      .split('')
      .map((d) => d + d)
      .join('');
  }
  const n = parseInt(digits, 16);
  return [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255];
}

export function rgbToHex([r, g, b]: Rgb): string {
  const to = (v: number) =>
    Math.round(Math.min(1, Math.max(0, v)) * 255)
      .toString(16)
      .padStart(2, '0');
  return `#${to(r)}${to(g)}${to(b)}`;
}

const toLinear = (v: number) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
const toGamma = (v: number) => (v <= 0.0031308 ? 12.92 * v : 1.055 * v ** (1 / 2.4) - 0.055);

export function rgbToOklch(rgb: Rgb): Oklch {
  const [r, g, b] = rgb.map(toLinear) as Rgb;

  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);

  const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
  const A = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
  const B = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;

  const c = Math.sqrt(A * A + B * B);
  let h = (Math.atan2(B, A) * 180) / Math.PI;
  if (h < 0) h += 360;

  return { l: L, c: c < 1e-4 ? 0 : c, h: c < 1e-4 ? 0 : h };
}

/** Converts to sRGB without clamping; components outside 0–1 mean out of gamut. */
export function oklchToRgbUnclamped({ l, c, h }: Oklch): Rgb {
  const hr = (h * Math.PI) / 180;
  const A = c * Math.cos(hr);
  const B = c * Math.sin(hr);

  const l_ = (l + 0.3963377774 * A + 0.2158037573 * B) ** 3;
  const m_ = (l - 0.1055613458 * A - 0.0638541728 * B) ** 3;
  const s_ = (l - 0.0894841775 * A - 1.291485548 * B) ** 3;

  return [
    toGamma(4.0767416621 * l_ - 3.3077115913 * m_ + 0.2309699292 * s_),
    toGamma(-1.2684380046 * l_ + 2.6097574011 * m_ - 0.3413193965 * s_),
    toGamma(-0.0041960863 * l_ - 0.7034186147 * m_ + 1.707614701 * s_),
  ];
}

const EPS = 1e-4;
const inGamut = (rgb: Rgb) => rgb.every((v) => v >= -EPS && v <= 1 + EPS);

/**
 * Maps an OKLCH colour into sRGB by reducing chroma (keeping lightness and hue),
 * so a saturated brand colour never shifts hue when it cannot be displayed exactly.
 */
export function toGamut(color: Oklch): Oklch {
  const l = Math.min(1, Math.max(0, color.l));
  if (inGamut(oklchToRgbUnclamped({ ...color, l }))) {
    return { ...color, l };
  }
  let lo = 0;
  let hi = color.c;
  for (let i = 0; i < 24; i++) {
    const mid = (lo + hi) / 2;
    if (inGamut(oklchToRgbUnclamped({ l, c: mid, h: color.h }))) lo = mid;
    else hi = mid;
  }
  return { l, c: lo, h: color.h };
}

export function oklchToHex(color: Oklch): string {
  return rgbToHex(oklchToRgbUnclamped(toGamut(color)));
}

export function hexToOklch(hex: string): Oklch {
  return rgbToOklch(hexToRgb(hex));
}
