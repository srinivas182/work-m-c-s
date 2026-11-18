/**
 * Keep working with a weak signal (Sprint 24.5, decision E). Six everyday changes — record a sale, money taken in
 * person, add a booking, add an expense, a cash-up count, a chat reply — are saved on the phone when there's no signal
 * and sent when it's back. Each carries its own Idempotency-Key, so a retry is never recorded twice. If something
 * changed meanwhile (the time was taken), the person is told and decides. Nothing that moves money online is queued.
 */
import { router } from '@inertiajs/react';

export interface Queued {
  id: string;
  url: string;
  data: Record<string, unknown>;
  label: string;
  at: string;
  problem?: string;
}

const KEY = 'wurk-outbox';
const QUEUEABLE = [
  /^\/[^/]+\/sales$/,
  /^\/[^/]+\/bookings$/,
  /^\/[^/]+\/bookings\/[^/]+\/paid$/,
  /^\/[^/]+\/money\/expenses$/,
  /^\/[^/]+\/money\/cash-up$/,
  /^\/[^/]+\/website\/chats\/ai\/[^/]+\/reply$/,
];
const LABELS: [RegExp, string][] = [
  [/\/sales$/, 'Sale'],
  [/\/paid$/, 'Payment in person'],
  [/\/bookings$/, 'Booking'],
  [/\/money\/expenses$/, 'Expense'],
  [/\/money\/cash-up$/, 'Cash-up'],
  [/\/reply$/, 'Chat reply'],
];

const listeners = new Set<() => void>();
const changed = () => listeners.forEach((l) => l());

export function outbox(): Queued[] {
  try {
    return JSON.parse(localStorage.getItem(KEY) ?? '[]') as Queued[];
  } catch {
    return [];
  }
}

function save(items: Queued[]) {
  try {
    localStorage.setItem(KEY, JSON.stringify(items));
  } catch {
    // storage full or blocked: nothing more we can do on this phone
  }
  changed();
}

export function onOutboxChange(fn: () => void): () => void {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

export function queueable(url: string): boolean {
  const path = new URL(url, window.location.origin).pathname;
  return QUEUEABLE.some((p) => p.test(path));
}

const uuid = () =>
  crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;

function add(url: string, data: Record<string, unknown>, id: string) {
  const path = new URL(url, window.location.origin).pathname;
  const label = LABELS.find(([p]) => p.test(path))?.[1] ?? 'Change';
  if (outbox().some((q) => q.id === id)) return;
  save([...outbox(), { id, url: path, data, label, at: new Date().toISOString() }]);
}

function cookie(name: string): string {
  const m = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return m ? decodeURIComponent(m[1] ?? '') : '';
}

let sending = false;
let version = ''; // the app's asset version, so the server answers a replay as a normal visit (24.5)

/** Send what's waiting, oldest first. Stops at the first network failure (still no signal). */
export async function flush(): Promise<void> {
  if (sending || !navigator.onLine) return;
  sending = true;
  try {
    for (const item of outbox().filter((q) => !q.problem)) {
      let response: Response;
      try {
        response = await fetch(item.url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'text/html, application/xhtml+xml',
            'X-Inertia': 'true',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': cookie('XSRF-TOKEN'),
            'X-Inertia-Version': version,
            'Idempotency-Key': item.id,
          },
          body: JSON.stringify(item.data),
        });
      } catch {
        break; // still no signal
      }
      const page = (await response.json().catch(() => null)) as {
        props?: { errors?: Record<string, string>; flash?: { warning?: string } };
      } | null;
      const errors = page?.props?.errors ?? {};
      const problem =
        Object.values(errors)[0] ??
        page?.props?.flash?.warning ??
        (response.ok ? undefined : `Not sent (${response.status}).`);
      save(
        problem
          ? outbox().map((q) => (q.id === item.id ? { ...q, problem } : q))
          : outbox().filter((q) => q.id !== item.id),
      );
    }
  } finally {
    sending = false;
  }
  if (outbox().length === 0) router.reload();
}

export function dismiss(id: string): void {
  save(outbox().filter((q) => q.id !== id));
}

/** Hook into every Inertia visit: give queueable changes their key, and keep them when the signal fails. */
export function startOffline(): void {
  if (typeof window === 'undefined') return;
  router.on('before', (event) => {
    const visit = event.detail.visit;
    if (new URL(visit.url.toString(), window.location.origin).pathname === '/sign-out') {
      // Nothing of this person's stays on a shared phone.
      void caches?.delete('wurk-v1');
      localStorage.removeItem(KEY);
      return;
    }
    if (
      visit.method !== 'post' ||
      !queueable(visit.url.toString()) ||
      visit.data instanceof FormData
    )
      return;
    const id = (visit.headers['Idempotency-Key'] as string | undefined) ?? uuid();
    visit.headers['Idempotency-Key'] = id;
    if (!navigator.onLine) {
      add(visit.url.toString(), visit.data as Record<string, unknown>, id);
      return false; // saved on the phone; sent when the signal is back
    }
  });
  // Inertia's network error doesn't say which visit failed: remember the queueable one in flight.
  let inFlight: { url: string; data: Record<string, unknown>; id: string } | null = null;
  router.on('start', (event) => {
    const visit = event.detail.visit;
    const id = visit.headers['Idempotency-Key'] as string | undefined;
    inFlight =
      visit.method === 'post' && id && queueable(visit.url.toString())
        ? { url: visit.url.toString(), data: visit.data as Record<string, unknown>, id }
        : null;
  });
  router.on('success', () => {
    inFlight = null;
  });
  router.on('navigate', (event) => {
    const first = version === '';
    version = String(event.detail.page.version ?? '');
    if (first) void flush(); // anything left from last time
  });
  router.on('error', () => {
    inFlight = null; // the server answered (e.g. a problem to fix now): nothing to queue
  });
  router.on('networkError', () => {
    if (inFlight) {
      add(inFlight.url, inFlight.data, inFlight.id); // the signal dropped mid-way: keep it, send later (once)
      inFlight = null;
      return false;
    }
  });
  window.addEventListener('online', () => void flush());
  if ('serviceWorker' in navigator && import.meta.env.PROD) {
    void navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => undefined);
  }
}
