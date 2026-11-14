import { router } from '@inertiajs/react';

/**
 * The last thing that went wrong in this tab (Sprint 16.3), so "Contact support" can include it — the person sees it
 * and can remove it before sending. Kept for this tab only; never sent anywhere on its own.
 */
export interface RecentError {
  message: string;
  page: string;
  at: string;
}

const KEY = 'wurk.recentError';

function remember(message: string): void {
  const e: RecentError = {
    message: message.slice(0, 300),
    page: window.location.pathname,
    at: new Date().toISOString(),
  };
  try {
    sessionStorage.setItem(KEY, JSON.stringify(e));
  } catch {
    // storage blocked — nothing to keep
  }
}

/** The last error in the past hour, if any. */
export function recentError(): RecentError | null {
  try {
    const e = JSON.parse(sessionStorage.getItem(KEY) ?? 'null') as RecentError | null;
    return e && Date.now() - new Date(e.at).getTime() < 3_600_000 ? e : null;
  } catch {
    return null;
  }
}

export function forgetRecentError(): void {
  try {
    sessionStorage.removeItem(KEY);
  } catch {
    // nothing to forget
  }
}

let installed = false;
let page: string | null = null;
const reported = new Set<string>();

/**
 * Tell WURK HQ's Watchtower (Sprint 18.0) about a crash in this browser: the message, the script stack and which
 * screen — never what was typed. The server masks personal data and groups repeats. Each message is sent once per
 * page load, at most five in all, and sending never gets in the way.
 */
export function reportCrash(
  message: string,
  stack: string | undefined,
  kind: 'error' | 'rejection' | 'render',
): void {
  if (typeof window === 'undefined' || reported.size >= 5 || reported.has(message)) return;
  reported.add(message);
  try {
    void fetch('/_wurk/client-error', {
      method: 'POST',
      keepalive: true,
      credentials: 'omit',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        message: message.slice(0, 1000),
        stack: (stack ?? '').slice(0, 8000),
        page,
        kind,
      }),
    }).catch(() => undefined);
  } catch {
    // reporting must never break the page
  }
}

/** Listen for script errors and failed requests (once per page load). */
export function watchForErrors(): void {
  if (installed || typeof window === 'undefined') return;
  installed = true;
  router.on('navigate', (e) => {
    page = e.detail.page.component;
  });
  window.addEventListener('error', (e) => {
    remember(e.message || 'A script error');
    if (e.error instanceof Error) reportCrash(e.error.message, e.error.stack, 'error');
  });
  window.addEventListener('unhandledrejection', (e) => {
    remember(e.reason instanceof Error ? e.reason.message : 'A request didn’t finish');
    if (e.reason instanceof Error) reportCrash(e.reason.message, e.reason.stack, 'rejection');
  });
  router.on('httpException', (e) => remember(`The server answered ${e.detail.response.status}`));
  router.on('networkError', () => remember('The connection dropped'));
}
