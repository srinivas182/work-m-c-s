import { useEffect, useState } from 'react';
import { __ } from '@/i18n';
import { dismiss, flush, onOutboxChange, outbox, type Queued } from '@/offline';

/** Offline and "waiting to send" (24.5): shown at the top of the back office only when there's something to say. */
export function OfflineBar() {
  const [online, setOnline] = useState(() =>
    typeof navigator === 'undefined' ? true : navigator.onLine,
  );
  const [items, setItems] = useState<Queued[]>(() =>
    typeof window === 'undefined' ? [] : outbox(),
  );
  useEffect(() => {
    const on = () => setOnline(true);
    const off = () => setOnline(false);
    window.addEventListener('online', on);
    window.addEventListener('offline', off);
    const stop = onOutboxChange(() => setItems(outbox()));
    return () => {
      window.removeEventListener('online', on);
      window.removeEventListener('offline', off);
      stop();
    };
  }, []);
  const waiting = items.filter((i) => !i.problem);
  const problems = items.filter((i) => i.problem);
  if (online && items.length === 0) return null;
  return (
    <div role="status" className="border-border bg-surface grid gap-1 border-b px-4 py-2 text-sm">
      {!online && (
        <p>
          {__(
            'You’re offline. What you see was last updated when you had a signal; changes are saved and sent when it’s back.',
          )}
        </p>
      )}
      {waiting.length > 0 && (
        <p>
          {__(':count waiting to send', { count: waiting.length })}:{' '}
          {waiting.map((w) => __(w.label)).join(', ')}.{' '}
          {online && (
            <button type="button" className="underline" onClick={() => void flush()}>
              {__('Send now')}
            </button>
          )}
        </p>
      )}
      {problems.map((p) => (
        <p key={p.id} className="text-danger">
          {__(':what wasn’t recorded', { what: __(p.label) })}: {p.problem}{' '}
          <button type="button" className="underline" onClick={() => dismiss(p.id)}>
            {__('OK')}
          </button>
        </p>
      ))}
    </div>
  );
}
