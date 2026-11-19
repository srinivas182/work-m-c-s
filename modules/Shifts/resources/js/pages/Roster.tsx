import { Head, Link, router, useForm } from '@inertiajs/react';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { PageHeader } from '@/design-system/components/feedback';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Shift {
  id: string;
  member_id: string;
  on: string;
  starts: string;
  ends: string;
}
interface Request {
  id: string;
  kind: 'leave' | 'swap';
  name: string;
  with: string | null;
  from: string | null;
  until: string | null;
  note: string | null;
}
interface Props {
  week: {
    monday: string;
    days: { date: string; label: string }[];
    previous: string;
    next: string;
  };
  members: { id: string; name: string }[];
  shifts: Shift[];
  requests: Request[];
  clock: {
    clock_check: 'none' | 'wifi' | 'location';
    wifi: string[];
    places: { name: string; lat: number; lng: number }[];
  };
  myIp: string;
}

const base = () => '/' + window.location.pathname.split('/')[1] + '/shifts';

/**
 * Settings → Shifts (Sprint 26.4): the week's roster, filled from booking hours so nothing is typed twice; leave and
 * swaps to approve; and the clock-in rule (off by default; or only on the business's Wi-Fi, or within 200 m).
 */
export default function Roster({ week, members, shifts, requests, clock, myIp }: Props) {
  const add = useForm({
    member_id: members[0]?.id ?? '',
    on: week.days[0]?.date ?? '',
    starts: '09:00',
    ends: '17:00',
  });
  const rule = useForm({ clock_check: clock.clock_check, wifi: clock.wifi, places: clock.places });

  const here = () =>
    navigator.geolocation?.getCurrentPosition((p) =>
      rule.setData('places', [
        ...rule.data.places,
        {
          name: __('Our place'),
          lat: Number(p.coords.latitude.toFixed(6)),
          lng: Number(p.coords.longitude.toFixed(6)),
        },
      ]),
    );

  return (
    <AppShell title={__('Shifts')}>
      <Head title={__('Shifts')} />
      <div className="grid gap-6">
        <PageHeader
          title={__('Shifts')}
          description={__('The week’s roster. Each person sees their own shifts on their phone.')}
        />

        <Card>
          <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
            <CardTitle>
              {week.days[0]?.label} – {week.days[6]?.label}
            </CardTitle>
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" size="sm" asChild>
                <Link href={`${base()}?week=${week.previous}`}>{__('Previous week')}</Link>
              </Button>
              <Button variant="secondary" size="sm" asChild>
                <Link href={`${base()}?week=${week.next}`}>{__('Next week')}</Link>
              </Button>
              <Button
                size="sm"
                onClick={() =>
                  router.post(`${base()}/fill`, { week: week.monday }, { preserveScroll: true })
                }
              >
                {__('Fill from booking hours')}
              </Button>
            </div>
          </CardHeader>
          <CardContent className="grid gap-4">
            {members.map((m) => {
              const mine = shifts.filter((s) => s.member_id === m.id);
              return (
                <section key={m.id} aria-label={m.name} className="grid gap-2">
                  <h3 className="font-semibold">{m.name}</h3>
                  {mine.length === 0 ? (
                    <p className="text-fg-muted text-sm">{__('No shifts this week.')}</p>
                  ) : (
                    <ul className="flex flex-wrap gap-2">
                      {mine.map((s) => (
                        <li
                          key={s.id}
                          className="bg-surface border-border flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm"
                        >
                          <span>
                            {week.days.find((d) => d.date === s.on)?.label} · {s.starts}–{s.ends}
                          </span>
                          <button
                            type="button"
                            className="text-fg-muted hover:text-fg underline"
                            aria-label={`${__('Remove')}: ${m.name}, ${s.on} ${s.starts}`}
                            onClick={() =>
                              router.delete(`${base()}/${s.id}`, { preserveScroll: true })
                            }
                          >
                            {__('Remove')}
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                </section>
              );
            })}
            <form
              className="grid gap-3 sm:grid-cols-5"
              onSubmit={(e) => {
                e.preventDefault();
                add.post(base(), { preserveScroll: true });
              }}
            >
              <Field label={__('Who')} error={add.errors.member_id}>
                <select
                  className="border-border bg-surface h-10 rounded-md border px-2"
                  value={add.data.member_id}
                  onChange={(e) => add.setData('member_id', e.target.value)}
                >
                  {members.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label={__('Day')} error={add.errors.on}>
                <select
                  className="border-border bg-surface h-10 rounded-md border px-2"
                  value={add.data.on}
                  onChange={(e) => add.setData('on', e.target.value)}
                >
                  {week.days.map((d) => (
                    <option key={d.date} value={d.date}>
                      {d.label}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label={__('From')} error={add.errors.starts}>
                <Input
                  type="time"
                  value={add.data.starts}
                  onChange={(e) => add.setData('starts', e.target.value)}
                />
              </Field>
              <Field label={__('Until')} error={add.errors.ends}>
                <Input
                  type="time"
                  value={add.data.ends}
                  onChange={(e) => add.setData('ends', e.target.value)}
                />
              </Field>
              <div className="flex items-end">
                <Button type="submit" variant="secondary" disabled={add.processing}>
                  {__('Add a shift')}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Leave and swaps to answer')}</CardTitle>
          </CardHeader>
          <CardContent>
            {requests.length === 0 ? (
              <p className="text-fg-muted text-sm">{__('Nothing waiting.')}</p>
            ) : (
              <ul className="divide-border grid divide-y">
                {requests.map((r) => (
                  <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                    <p className="text-sm">
                      <span className="font-medium">{r.name}</span>{' '}
                      {r.kind === 'leave'
                        ? `${__('asks for leave')} ${r.from} – ${r.until}`
                        : `${__('asks to give a shift to')} ${r.with}`}
                      {r.note && <span className="text-fg-muted"> · “{r.note}”</span>}
                    </p>
                    <div className="flex gap-2">
                      <Button
                        size="sm"
                        onClick={() =>
                          router.post(
                            `${base()}/requests/${r.id}`,
                            { approve: true },
                            { preserveScroll: true },
                          )
                        }
                      >
                        {__('Approve')}
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                          router.post(
                            `${base()}/requests/${r.id}`,
                            { approve: false },
                            { preserveScroll: true },
                          )
                        }
                      >
                        {__('Decline')}
                      </Button>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Clocking in')}</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              className="grid gap-3 text-sm"
              onSubmit={(e) => {
                e.preventDefault();
                rule.put(`${base()}/clock`, { preserveScroll: true });
              }}
            >
              <fieldset className="grid gap-2">
                <legend className="font-medium">{__('Who can clock in')}</legend>
                {(
                  [
                    ['none', __('From anywhere (best for shared phones and weak GPS)')],
                    ['wifi', __('Only on our Wi-Fi')],
                    ['location', __('Only within 200 m of our place')],
                  ] as const
                ).map(([v, label]) => (
                  <label key={v} className="flex items-center gap-2">
                    <input
                      type="radio"
                      name="clock_check"
                      value={v}
                      checked={rule.data.clock_check === v}
                      onChange={() => rule.setData('clock_check', v)}
                    />
                    {label}
                  </label>
                ))}
              </fieldset>
              {rule.errors.clock_check && (
                <p role="alert" className="text-danger-11">
                  {rule.errors.clock_check}
                </p>
              )}
              <p className="text-fg-muted">
                {__('Our Wi-Fi:')}{' '}
                {rule.data.wifi.length === 0 ? __('not set') : rule.data.wifi.join(', ')}{' '}
                <button
                  type="button"
                  className="underline"
                  onClick={() => rule.setData('wifi', [...new Set([...rule.data.wifi, myIp])])}
                >
                  {__('This is our Wi-Fi')}
                </button>
              </p>
              <p className="text-fg-muted">
                {__('Our place:')}{' '}
                {rule.data.places.length === 0
                  ? __('not set')
                  : rule.data.places.map((p) => `${p.lat}, ${p.lng}`).join(' · ')}{' '}
                <button type="button" className="underline" onClick={here}>
                  {__('We’re here')}
                </button>
              </p>
              <div>
                <Button type="submit" variant="secondary" disabled={rule.processing}>
                  {__('Save')}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
