import { generateNeutral, generatePalette, type ModePalette, type Palette } from './palette';

export type BrandMode = 'light' | 'dark';

export interface BrandTheme {
  brand: Palette;
  neutral: Palette;
}

export function buildBrandTheme(brandHex: string): BrandTheme {
  return { brand: generatePalette(brandHex), neutral: generateNeutral(brandHex) };
}

function scaleVars(prefix: string, palette: ModePalette): Record<string, string> {
  const vars: Record<string, string> = {};
  palette.scale.forEach((value, i) => {
    vars[`--wurk-${prefix}-${i + 1}`] = value;
  });
  vars[`--wurk-${prefix}-contrast`] = palette.onSolid;
  return vars;
}

/** CSS custom properties for one mode — applied to :root or any themed container. */
export function brandCssVariables(theme: BrandTheme, mode: BrandMode): Record<string, string> {
  return {
    ...scaleVars('brand', theme.brand[mode]),
    ...scaleVars('neutral', theme.neutral[mode]),
  };
}

/** Serialises variables to a CSS declaration block body. */
export function toCssDeclarations(vars: Record<string, string>, indent = '  '): string {
  return Object.entries(vars)
    .map(([k, v]) => `${indent}${k}: ${v};`)
    .join('\n');
}

/** Applies a brand theme to an element (e.g. document.documentElement) at runtime. */
export function applyBrandTheme(element: HTMLElement, theme: BrandTheme, mode: BrandMode): void {
  for (const [key, value] of Object.entries(brandCssVariables(theme, mode))) {
    element.style.setProperty(key, value);
  }
}

export function clearBrandTheme(element: HTMLElement): void {
  for (const key of Object.keys(brandCssVariables(buildBrandTheme('#808080'), 'light'))) {
    element.style.removeProperty(key);
  }
}
