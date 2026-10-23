import type { CSSProperties } from 'react';
import { generateNeutral, generatePalette } from '@/design-system/brand/palette';
import type { Branding } from './types';

type FontPairing = { display: string; body: string };

const MODERN: FontPairing = {
  display: "'Plus Jakarta Sans Variable', 'Plus Jakarta Sans', system-ui, sans-serif",
  body: "'Inter Variable', Inter, system-ui, sans-serif",
};

export const FONT_PAIRINGS: Record<string, FontPairing> = {
  modern: MODERN,
  classic: {
    display: "'Playfair Display Variable', 'Playfair Display', Georgia, serif",
    body: "'Source Sans 3 Variable', 'Source Sans 3', system-ui, sans-serif",
  },
  friendly: {
    display: "'Nunito Variable', Nunito, system-ui, sans-serif",
    body: "'Nunito Sans Variable', 'Nunito Sans', system-ui, sans-serif",
  },
  elegant: {
    display: "'Cormorant Garamond Variable', 'Cormorant Garamond', Georgia, serif",
    body: 'Lato, system-ui, sans-serif',
  },
  bold: {
    display: "'Archivo Variable', Archivo, system-ui, sans-serif",
    body: "'Archivo Variable', Archivo, system-ui, sans-serif",
  },
  clean: {
    display: "'DM Sans Variable', 'DM Sans', system-ui, sans-serif",
    body: "'DM Sans Variable', 'DM Sans', system-ui, sans-serif",
  },
  editorial: {
    display: "'Fraunces Variable', Fraunces, Georgia, serif",
    body: "'Work Sans Variable', 'Work Sans', system-ui, sans-serif",
  },
  technical: {
    display: "'Space Grotesk Variable', 'Space Grotesk', system-ui, sans-serif",
    body: "'IBM Plex Sans Variable', 'IBM Plex Sans', system-ui, sans-serif",
  },
};

const RADIUS = { square: '2px', soft: '14px', round: '28px' } as const;

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
  } as CSSProperties;
}
