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

/** Listen for script errors and failed requests (once per page load). */
export function watchForErrors(): void {
  if (installed || typeof window === 'undefined') return;
  installed = true;
  window.addEventListener('error', (e) => remember(e.message || 'A script error'));
  window.addEventListener('unhandledrejection', (e) =>
    remember(e.reason instanceof Error ? e.reason.message : 'A request didn’t finish'),
  );
  router.on('httpException', (e) => remember(`The server answered ${e.detail.response.status}`));
  router.on('networkError', () => remember('The connection dropped'));
}
