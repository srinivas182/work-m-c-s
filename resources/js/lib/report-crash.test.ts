import { afterEach, describe, expect, it, vi } from 'vitest';
import { reportCrash } from './recent-error';

describe('reportCrash (Watchtower, Sprint 18.0)', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('sends a crash once per message, at most five per page load, without cookies', () => {
    const fetch = vi.fn(() => Promise.resolve(new Response(null, { status: 204 })));
    vi.stubGlobal('fetch', fetch);
    reportCrash('Boom', 'Error: Boom\n at app.js:1:1', 'error');
    reportCrash('Boom', 'again', 'error');
    expect(fetch).toHaveBeenCalledTimes(1);
    const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('/_wurk/client-error');
    expect(init).toMatchObject({ method: 'POST', keepalive: true, credentials: 'omit' });
    expect(JSON.parse(String(init.body))).toMatchObject({ message: 'Boom', kind: 'error' });
    for (const m of ['a', 'b', 'c', 'd', 'e', 'f']) reportCrash(m, undefined, 'rejection');
    expect(fetch).toHaveBeenCalledTimes(5);
  });

  it('never throws when sending fails', () => {
    vi.stubGlobal('fetch', () => {
      throw new Error('offline');
    });
    expect(() => reportCrash('Another', undefined, 'render')).not.toThrow();
  });
});
