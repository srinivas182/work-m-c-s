import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { Switch } from '@/design-system/components/choice';
import { PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Props {
  publicKey: string;
  kinds: { key: string; label: string }[];
  off: string[];
  phones: number;
}

type State = 'checking' | 'unsupported' | 'blocked' | 'off' | 'on';

const base = () => '/' + window.location.pathname.split('/')[1] + '/settings/notifications';

function xsrf(): string {
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return m ? decodeURIComponent(m[1] ?? '') : '';
}

async function postJson(url: string, body: unknown): Promise<{ phones?: number } | null> {
  const res = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': xsrf(),
    },
    body: JSON.stringify(body),
  }).catch(() => null);
  return res?.ok ? ((await res.json()) as { phones?: number }) : null;
}

/** The browser wants the VAPID key as bytes. */
function keyBytes(b64url: string): ArrayBuffer {
  const b64 =
    b64url.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (b64url.length % 4)) % 4);
  return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)).buffer as ArrayBuffer;
}

/** What the browser can do, before asking it about this phone's subscription. */
function initialState(): State {
  if (
    typeof window === 'undefined' ||
    !('serviceWorker' in navigator) ||
    !('PushManager' in window) ||
    !('Notification' in window)
  ) {
    return 'unsupported';
  }
  return Notification.permission === 'denied' ? 'blocked' : 'checking';
}

/**
 * Settings → Notifications on my phone (Sprint 26.2): turn on WURK's notifications for this phone, choose what to
 * be told about in this business, and send a test. Works once WURK is installed from the browser (Add to Home
 * screen) — on iPhone, push only works from the installed app.
 */
export default function Settings({ publicKey, kinds, off, phones }: Props) {
  const [state, setState] = useState<State>(initialState);
  const [count, setCount] = useState(phones);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  useEffect(() => {
    if (state !== 'checking') return;
    void navigator.serviceWorker.getRegistration('/').then(async (reg) => {
      const sub = await reg?.pushManager.getSubscription();
      setState(sub ? 'on' : 'off');
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps -- once, on opening
  }, []);

  const turnOn = async () => {
    setBusy(true);
    setProblem(null);
    try {
      const reg =
        (await navigator.serviceWorker.getRegistration('/')) ??
        (await navigator.serviceWorker.register('/sw.js', { scope: '/' }));
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        setState(permission === 'denied' ? 'blocked' : 'off');
        return;
      }
      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: keyBytes(publicKey),
      });
      const saved = await postJson(`${base()}/phones`, {
        ...sub.toJSON(),
        device: navigator.userAgent.slice(0, 120),
      });
      if (!saved) throw new Error('not saved');
      setCount(saved.phones ?? count + 1);
      setState('on');
    } catch {
      setProblem(__('Notifications couldn’t be turned on. Check your signal and try again.'));
    } finally {
      setBusy(false);
    }
  };

  const turnOff = async () => {
    setBusy(true);
    const reg = await navigator.serviceWorker.getRegistration('/');
    const sub = await reg?.pushManager.getSubscription();
    if (sub) {
      const saved = await postJson(`${base()}/phones/remove`, { endpoint: sub.endpoint });
      await sub.unsubscribe();
      setCount(saved?.phones ?? Math.max(0, count - 1));
    }
    setState('off');
    setBusy(false);
  };

  const toggle = (key: string, on: boolean) =>
    router.put(
      base(),
      { off: on ? off.filter((k) => k !== key) : [...off, key] },
      { preserveScroll: true },
    );

  return (
    <AppShell title={__('Notifications on my phone')}>
      <Head title={__('Notifications on my phone')} />
      <div className="mx-auto grid max-w-2xl gap-6">
        <PageHeader
          title={__('Notifications on my phone')}
          description={__(
            'WURK tells you straight away about new bookings, chats, orders and payments — like a message, without opening the app.',
          )}
        />
        <Card>
          <CardHeader>
            <CardTitle>{__('This phone')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3 text-sm">
            <p role="status">
              {state === 'checking' && __('Checking…')}
              {state === 'on' && __('Notifications are on for this phone.')}
              {state === 'off' && __('Notifications are off for this phone.')}
              {state === 'blocked' &&
                __(
                  'This phone blocks notifications from WURK. Allow them in the browser’s site settings, then come back.',
                )}
              {state === 'unsupported' &&
                __(
                  'This browser can’t show notifications. On iPhone, add WURK to your Home Screen first (Share → Add to Home Screen) and open it from there.',
                )}
            </p>
            {problem && (
              <p role="alert" className="text-danger-11">
                {problem}
              </p>
            )}
            <div className="flex flex-wrap gap-2">
              {state === 'off' && (
                <Button onClick={() => void turnOn()} disabled={busy}>
                  {__('Turn on for this phone')}
                </Button>
              )}
              {state === 'on' && (
                <>
                  <Button
                    variant="secondary"
                    onClick={() => router.post(`${base()}/test`, {}, { preserveScroll: true })}
                  >
                    {__('Send a test')}
                  </Button>
                  <Button variant="ghost" onClick={() => void turnOff()} disabled={busy}>
                    {__('Turn off for this phone')}
                  </Button>
                </>
              )}
            </div>
            <p className="text-fg-muted">
              {count === 1
                ? __('You get notifications on 1 phone.')
                : `${__('Phones you get notifications on:')} ${count}`}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Tell me about')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3">
            {kinds.map((k) => (
              <label key={k.key} className="flex items-center justify-between gap-3 text-sm">
                <span>{k.label}</span>
                <Switch
                  aria-label={k.label}
                  checked={!off.includes(k.key)}
                  onCheckedChange={(on) => toggle(k.key, on)}
                />
              </label>
            ))}
            <p className="text-fg-muted text-sm">
              {__('Only what your role lets you see. These choices are for this business only.')}
            </p>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
