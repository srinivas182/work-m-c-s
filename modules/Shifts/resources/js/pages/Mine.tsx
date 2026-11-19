import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { PageHeader } from '@/design-system/components/feedback';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Shift {
  id: string;
  on: string;
  label: string;
  starts: string;
  ends: string;
}
interface Ask {
  id: string;
  kind: 'leave' | 'swap';
  from: string | null;
  until: string | null;
  status: string;
}
interface Props {
  shifts: Shift[];
  requests: Ask[];
  others: { id: string; name: string }[];
  clockedIn: { id: string; in_at: string } | null;
  check: 'none' | 'wifi' | 'location';
}

const base = () => '/' + window.location.pathname.split('/')[1] + '/shifts/mine';

/** My shifts (Sprint 26.4): this and next week's shifts, clocking in and out, and asking for leave or a swap. */
export default function Mine({ shifts, requests, others, clockedIn, check }: Props) {
  const leave = useForm({ from: '', until: '', note: '' });
  const [swap, setSwap] = useState<{ shift: string; with: string }>({
    shift: '',
    with: others[0]?.id ?? '',
  });
  const [locating, setLocating] = useState(false);

  const clockIn = () => {
    if (check !== 'location' || !navigator.geolocation) {
      router.post(`${base()}/clock-in`, {}, { preserveScroll: true });
      return;
    }
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (p) => {
        setLocating(false);
        router.post(
          `${base()}/clock-in`,
          { lat: p.coords.latitude, lng: p.coords.longitude },
          { preserveScroll: true },
        );
      },
      () => {
        setLocating(false);
        router.post(`${base()}/clock-in`, {}, { preserveScroll: true });
      },
      { enableHighAccuracy: true, timeout: 15000 },
    );
  };

  return (
    <AppShell title={__('My shifts')}>
      <Head title={__('My shifts')} />
      <div className="mx-auto grid max-w-2xl gap-6">
        <PageHeader title={__('My shifts')} />
        <Card>
          <CardContent className="flex flex-wrap items-center justify-between gap-3 pt-6">
            <p role="status">
              {clockedIn
                ? `${__('Clocked in at')} ${new Date(clockedIn.in_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`
                : __('Not clocked in')}
            </p>
            {clockedIn ? (
              <Button
                variant="secondary"
                onClick={() => router.post(`${base()}/clock-out`, {}, { preserveScroll: true })}
              >
                {__('Clock out')}
              </Button>
            ) : (
              <Button onClick={clockIn} disabled={locating}>
                {locating ? __('Finding where you are…') : __('Clock in')}
              </Button>
            )}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('This week and next')}</CardTitle>
          </CardHeader>
          <CardContent>
            {shifts.length === 0 ? (
              <p className="text-fg-muted text-sm">{__('No shifts yet.')}</p>
            ) : (
              <ul className="divide-border grid divide-y">
                {shifts.map((s) => (
                  <li key={s.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                    <span>
                      <span className="font-medium">{s.label}</span> · {s.starts}–{s.ends}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Ask for leave')}</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              className="grid gap-3 sm:grid-cols-2"
              onSubmit={(e) => {
                e.preventDefault();
                leave.post(`${base()}/leave`, {
                  preserveScroll: true,
                  onSuccess: () => leave.reset(),
                });
              }}
            >
              <Field label={__('First day')} error={leave.errors.from}>
                <Input
                  type="date"
                  value={leave.data.from}
                  onChange={(e) => leave.setData('from', e.target.value)}
                />
              </Field>
              <Field label={__('Last day')} error={leave.errors.until}>
                <Input
                  type="date"
                  value={leave.data.until}
                  onChange={(e) => leave.setData('until', e.target.value)}
                />
              </Field>
              <Field label={__('Note (optional)')} error={leave.errors.note}>
                <Input
                  value={leave.data.note}
                  maxLength={300}
                  onChange={(e) => leave.setData('note', e.target.value)}
                />
              </Field>
              <div className="flex items-end">
                <Button type="submit" variant="secondary" disabled={leave.processing}>
                  {__('Ask')}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
        {shifts.length > 0 && others.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>{__('Swap a shift')}</CardTitle>
            </CardHeader>
            <CardContent>
              <form
                className="grid gap-3 sm:grid-cols-3"
                onSubmit={(e) => {
                  e.preventDefault();
                  router.post(
                    `${base()}/swap`,
                    { shift_id: swap.shift, swap_with: swap.with },
                    { preserveScroll: true },
                  );
                }}
              >
                <Field label={__('My shift')}>
                  <select
                    className="border-border bg-surface h-10 rounded-md border px-2"
                    value={swap.shift}
                    onChange={(e) => setSwap({ ...swap, shift: e.target.value })}
                  >
                    <option value="">{__('Choose…')}</option>
                    {shifts.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.label} {s.starts}–{s.ends}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label={__('Who takes it')}>
                  <select
                    className="border-border bg-surface h-10 rounded-md border px-2"
                    value={swap.with}
                    onChange={(e) => setSwap({ ...swap, with: e.target.value })}
                  >
                    {others.map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.name}
                      </option>
                    ))}
                  </select>
                </Field>
                <div className="flex items-end">
                  <Button type="submit" variant="secondary" disabled={swap.shift === ''}>
                    {__('Ask')}
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}
        {requests.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>{__('What I asked for')}</CardTitle>
            </CardHeader>
            <CardContent>
              <ul className="grid gap-2 text-sm">
                {requests.map((r) => (
                  <li key={r.id} className="flex items-center justify-between gap-3">
                    <span>
                      {r.kind === 'leave'
                        ? `${__('Leave')} ${r.from} – ${r.until}`
                        : __('Shift swap')}
                    </span>
                    <Badge>
                      {r.status === 'pending'
                        ? __('Waiting')
                        : r.status === 'approved'
                          ? __('Approved')
                          : __('Declined')}
                    </Badge>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
