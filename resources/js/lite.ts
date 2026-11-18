/**
 * Lite mode in the browser (Sprint 24.4). Notes a slow connection for the server (a cookie), so the next page comes
 * lighter, and counts roughly how much data WURK used on this phone this month (from the browser's own transfer sizes).
 */

const MONTH = () => new Date().toISOString().slice(0, 7);

function setCookie(name: string, value: string, days: number) {
  document.cookie = `${name}=${value}; path=/; max-age=${days * 86400}; SameSite=Lax`;
}

export function startLite(): void {
  if (typeof window === 'undefined') return;
  const conn = (
    navigator as Navigator & { connection?: { effectiveType?: string; saveData?: boolean } }
  ).connection;
  const slow =
    !!conn &&
    (conn.saveData === true || ['slow-2g', '2g', '3g'].includes(conn.effectiveType ?? ''));
  setCookie('wurk_slow', slow ? '1' : '0', 1);
  try {
    const observer = new PerformanceObserver((list) => {
      let bytes = 0;
      for (const e of list.getEntries() as PerformanceResourceTiming[])
        bytes += e.transferSize || 0;
      if (bytes === 0) return;
      const key = `wurk-data-${MONTH()}`;
      localStorage.setItem(key, String(Number(localStorage.getItem(key) ?? '0') + bytes));
    });
    observer.observe({ type: 'resource', buffered: true });
    observer.observe({ type: 'navigation', buffered: true });
  } catch {
    // older browsers: no counter
  }
}

/** About how much WURK used on this phone this month, in MB (one decimal). */
export function dataThisMonth(): number {
  try {
    return Math.round(Number(localStorage.getItem(`wurk-data-${MONTH()}`) ?? '0') / 104857.6) / 10;
  } catch {
    return 0;
  }
}

/** The person's choice for this phone: automatic, always on, or off. */
export function setLiteChoice(choice: 'auto' | 'on' | 'off'): void {
  if (choice === 'auto') document.cookie = 'wurk_lite=; path=/; max-age=0';
  else setCookie('wurk_lite', choice, 365);
}
