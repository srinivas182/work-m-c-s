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

/** How the business's currency is written (Sprint 25.1b), shared by the server with every page. */
export interface Currency {
  code: string;
  symbol: string;
  decimals: number;
  decimal: string;
  thousands: string;
  after: boolean;
}

/** WURK's own money (plans, credits, commission) and the default before a page says otherwise. */
export const ZAR: Currency = {
  code: 'ZAR',
  symbol: 'R',
  decimals: 2,
  decimal: '.',
  thousands: ' ',
  after: false,
};

let current: Currency | null = null;

/** A business's own website carries its currency on <html data-currency> (no page props there). */
function fromDocument(): Currency {
  try {
    const raw =
      typeof document === 'undefined' ? undefined : document.documentElement.dataset.currency;
    return raw ? (JSON.parse(raw) as Currency) : ZAR;
  } catch {
    return ZAR;
  }
}

/** Set from each page's shared `currency` prop (app.tsx), or by the website renderer. */
export function setCurrency(c: Currency | null | undefined): void {
  current = c ?? null;
}

/**
 * Money exactly as the server writes it (Wurk\Platform\Money\Money::write): amounts are hundredths of the currency,
 * whatever it is; the business's country decides the symbol, decimals and separators (R1 380.00, KSh1,380.00,
 * 1 380 CFA). A reader of French or Portuguese sees a point-decimal currency their way: 1 380,00 R.
 */
export function money(
  cents: number,
  code: string = locale(),
  currency: Currency = current ?? fromDocument(),
): string {
  let { decimal, thousands, after } = currency;
  if ((code === 'fr' || code === 'pt') && decimal === '.') {
    [decimal, thousands, after] = [',', ' ', true];
  }
  const [whole = '0', part] = (Math.abs(cents) / 100).toFixed(currency.decimals).split('.');
  const n = `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, thousands)}${part ? decimal + part : ''}`;
  const sign = cents < 0 ? '-' : '';
  return after ? `${sign}${n} ${currency.symbol}` : `${sign}${currency.symbol}${n}`;
}

/** Like money(), without ".00" on a whole amount: "from R350" on a website. */
export function moneyShort(cents: number, code: string = locale()): string {
  const c = current ?? fromDocument();
  return money(cents, code, cents % 100 === 0 ? { ...c, decimals: 0 } : c);
}

/** The business's currency symbol, for labels such as "Amount (R)". */
export const currencySymbol = (): string => (current ?? fromDocument()).symbol;

/** WURK's own money, always in rand. */
export const zar = (cents: number, code: string = locale()): string => money(cents, code, ZAR);
