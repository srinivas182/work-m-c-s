/**
 * Rand amounts formatted the same everywhere (server and every browser):
 * a space between thousands and a decimal point, e.g. R2 990 and R343.85.
 * Locale formatting is avoided because runtimes disagree (en-ZA can give "343,85").
 */
export function rand(cents: number): string {
  const negative = cents < 0;
  const abs = Math.abs(Math.round(cents));
  const whole = Math.floor(abs / 100)
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  const fraction = abs % 100;
  return `${negative ? '-' : ''}R${whole}${fraction === 0 ? '' : '.' + String(fraction).padStart(2, '0')}`;
}
