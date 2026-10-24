/** Dates entered as YYYY-MM-DD. Invalid or empty dates mean "no limit". */
export function parseDay(value: unknown): Date | null {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
  const d = new Date(`${value}T00:00:00`);
  return Number.isNaN(d.getTime()) ? null : d;
}

/** True when today falls within [starts, ends] (inclusive; missing ends are open). */
export function isCurrent(starts: unknown, ends: unknown, today = new Date()): boolean {
  const day = new Date(today.getFullYear(), today.getMonth(), today.getDate());
  const s = parseDay(starts);
  const e = parseDay(ends);
  return (!s || day >= s) && (!e || day <= e);
}

export function formatDay(value: unknown): string {
  const d = parseDay(value);
  return d ? d.toLocaleDateString('en-ZA', { day: 'numeric', month: 'long', year: 'numeric' }) : '';
}
