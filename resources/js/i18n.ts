/**
 * WURK's languages in the browser (Sprint 24.1). Texts are written in English in the code and wrapped in `t()`;
 * the translations for the person's language arrive as a script before the app (window.__wurkI18n). A text with no
 * translation shows in English, so nothing is ever blank. Placeholders are Laravel-style (":name"), with the values as the second argument.
 */

declare global {
  interface Window {
    __wurkI18n?: Record<string, string>;
  }
}

const messages = (): Record<string, string> =>
  typeof window === 'undefined' ? {} : (window.__wurkI18n ?? {});

export function t(text: string, values: Record<string, string | number> = {}): string {
  let out = messages()[text] ?? text;
  for (const [key, value] of Object.entries(values)) {
    out = out.split(`:${key}`).join(String(value));
  }
  return out;
}

/** The same as t(), under a name pages don't use for anything else (the codemod and most pages use this one). */
export const __ = t;

/** The page's language code (from <html lang>), e.g. 'zu'. */
export function locale(): string {
  return typeof document === 'undefined' ? 'en' : document.documentElement.lang.slice(0, 2) || 'en';
}

/**
 * Rand in the language's own style, exactly as the server writes it (Wurk\Platform\I18n\Locales::money):
 * R1 380.00 in South Africa's languages and English; 1 380,00 R in French and Portuguese.
 */
export function money(cents: number, code: string = locale()): string {
  const comma = code === 'fr' || code === 'pt';
  const [whole = '0', part = '00'] = (Math.abs(cents) / 100).toFixed(2).split('.');
  const n = `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ' ')}${comma ? ',' : '.'}${part}`;
  const sign = cents < 0 ? '-' : '';
  return comma ? `${sign}${n} R` : `${sign}R${n}`;
}
