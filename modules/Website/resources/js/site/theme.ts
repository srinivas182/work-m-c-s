import type { CSSProperties } from 'react';
import { generateNeutral, generatePalette } from '@/design-system/brand/palette';
import type { Branding, SiteStyle } from './types';

type FontPairing = { display: string; body: string };

const MODERN: FontPairing = {
  display:
    "'Plus Jakarta Sans Variable', 'Plus Jakarta Sans', 'Plus Jakarta Sans Fallback', system-ui, sans-serif",
  body: "'Inter Variable', Inter, 'Inter Fallback', system-ui, sans-serif",
};

export const FONT_PAIRINGS: Record<string, FontPairing> = {
  modern: MODERN,
  classic: {
    display:
      "'Playfair Display Variable', 'Playfair Display', 'Playfair Display Fallback', Georgia, serif",
    body: "'Source Sans 3 Variable', 'Source Sans 3', 'Source Sans 3 Fallback', system-ui, sans-serif",
  },
  friendly: {
    display: "'Nunito Variable', Nunito, 'Nunito Fallback', system-ui, sans-serif",
    body: "'Nunito Sans Variable', 'Nunito Sans', 'Nunito Sans Fallback', system-ui, sans-serif",
  },
  elegant: {
    display:
      "'Cormorant Garamond Variable', 'Cormorant Garamond', 'Cormorant Garamond Fallback', Georgia, serif",
    body: "Lato, 'Lato Fallback', system-ui, sans-serif",
  },
  bold: {
    display: "'Archivo Variable', Archivo, 'Archivo Fallback', system-ui, sans-serif",
    body: "'Archivo Variable', Archivo, 'Archivo Fallback', system-ui, sans-serif",
  },
  clean: {
    display: "'DM Sans Variable', 'DM Sans', 'DM Sans Fallback', system-ui, sans-serif",
    body: "'DM Sans Variable', 'DM Sans', 'DM Sans Fallback', system-ui, sans-serif",
  },
  editorial: {
    display: "'Fraunces Variable', Fraunces, 'Fraunces Fallback', Georgia, serif",
    body: "'Work Sans Variable', 'Work Sans', 'Work Sans Fallback', system-ui, sans-serif",
  },
  technical: {
    display:
      "'Space Grotesk Variable', 'Space Grotesk', 'Space Grotesk Fallback', system-ui, sans-serif",
    body: "'IBM Plex Sans Variable', 'IBM Plex Sans', 'IBM Plex Sans Fallback', system-ui, sans-serif",
  },
};

const RADIUS = { square: '2px', soft: '14px', round: '28px' } as const;

/**
 * The three styles of every design (Sprint 20.1). Choosing one sets its type pairing and corners (the owner can still
 * change those after); the rest of the look comes from these variables and site.css.
 */
export const STYLES: Record<
  SiteStyle,
  { label: string; description: string; font_pairing: string; corners: Branding['corners'] }
> = {
  luxury: {
    label: 'Luxury',
    description: 'Refined serif type, generous space and deep, quiet bands.',
    font_pairing: 'elegant',
    corners: 'square',
  },
  minimal: {
    label: 'Modern minimal',
    description: 'Clean type, white space and fine lines; one accent colour.',
    font_pairing: 'clean',
    corners: 'soft',
  },
  bold: {
    label: 'Bold',
    description: 'Big, confident type and solid colour bands.',
    font_pairing: 'bold',
    corners: 'round',
  },
};

const STYLE_VARS: Record<SiteStyle, Record<string, string>> = {
  luxury: {
    '--s-section-y': 'clamp(5rem, 10vw, 8.5rem)',
    '--s-heading-weight': '500',
    '--s-heading-tracking': '0.005em',
    '--s-heading-scale': '1.12',
    '--s-btn-case': 'uppercase',
    '--s-btn-tracking': '0.14em',
    '--s-btn-size': '0.8125rem',
  },
  minimal: {
    '--s-section-y': 'clamp(4rem, 8vw, 6rem)',
    '--s-heading-weight': '650',
    '--s-heading-tracking': '-0.02em',
    '--s-heading-scale': '1',
    '--s-btn-case': 'none',
    '--s-btn-tracking': '0',
    '--s-btn-size': '1rem',
  },
  bold: {
    '--s-section-y': 'clamp(4rem, 8vw, 6.5rem)',
    '--s-heading-weight': '800',
    '--s-heading-tracking': '-0.035em',
    '--s-heading-scale': '1.22',
    '--s-btn-case': 'none',
    '--s-btn-tracking': '-0.005em',
    '--s-btn-size': '1.0625rem',
  },
};

/**
 * A site's look as CSS variables, scoped to the site's root element so one
 * business's palette never reaches another's (or the back office). Colours come
 * from the Sprint 0 engine, which guarantees WCAG 2.2 AA pairings.
 */
export function siteTheme(branding: Branding): CSSProperties {
  const brand = generatePalette(branding.primary).light;
  const neutral = generateNeutral(branding.primary).light;
  const accent = branding.secondary ? generatePalette(branding.secondary).light : brand;
  const fonts = FONT_PAIRINGS[branding.font_pairing] ?? MODERN;
  const n = neutral.scale;
  const b = brand.scale;

  return {
    '--s-bg': n[0],
    '--s-surface': n[1],
    '--s-surface-2': n[2],
    '--s-border': n[5],
    '--s-fg': n[11],
    '--s-fg-muted': n[10],
    '--s-brand': b[8],
    '--s-on-brand': brand.onSolid,
    '--s-brand-text': b[10],
    '--s-brand-soft': b[2],
    '--s-brand-softer': b[1],
    '--s-accent': accent.scale[8],
    '--s-on-accent': accent.onSolid,
    '--s-font-display': fonts.display,
    '--s-font-body': fonts.body,
    '--s-radius': RADIUS[branding.corners] ?? RADIUS.soft,
    ...STYLE_VARS[branding.style ?? 'minimal'],
    // The colours of a deep band (Luxury's alternate sections): the dark side of the same palettes, so text on it is
    // as readable as on the light side, whatever colour the owner picks.
    ...inverted(branding),
  } as CSSProperties;
}

function inverted(branding: Branding): Record<string, string> {
  const brand = generatePalette(branding.primary).dark;
  const n = generateNeutral(branding.primary).dark.scale;
  const accent = branding.secondary ? generatePalette(branding.secondary).dark : brand;

  return {
    '--s-inv-bg': n[0],
    '--s-inv-surface': n[1],
    '--s-inv-surface-2': n[2],
    '--s-inv-border': n[5],
    '--s-inv-fg': n[11],
    '--s-inv-fg-muted': n[10],
    '--s-inv-brand': brand.scale[8],
    '--s-inv-on-brand': brand.onSolid,
    '--s-inv-brand-text': brand.scale[10],
    '--s-inv-brand-soft': brand.scale[2],
    '--s-inv-brand-softer': brand.scale[1],
    '--s-inv-accent': accent.scale[8],
    '--s-inv-on-accent': accent.onSolid,
  };
}
