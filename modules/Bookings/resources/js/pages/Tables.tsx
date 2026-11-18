import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/design-system/components/card';
import { Checkbox } from '@/design-system/components/choice';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Row {
  id: string;
  party: number;
  customer: string;
  phone: string | null;
  notes: string | null;
  status: string;
  source: string;
  time: string;
}
interface Settings {
  enabled: boolean;
  covers: number;
  sitting_minutes: number;
  step_minutes: number;
  max_party: number;
  notice_minutes: number;
}
interface Props {
  date: string;
  timezone: string;
  reservations: Row[];
  settings: Settings;
  hours: { weekday: number; starts: string; ends: string }[];
}

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const base = () => window.location.pathname.replace(/\/$/, '');
const shift = (iso: string, n: number) => {
  const d = new Date(`${iso}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
};

function SettingsCard({ settings, hours }: Pick<Props, 'settings' | 'hours'>) {
  const [days, setDays] = useState(
    DAYS.map((_, d) =>
      hours.filter((h) => h.weekday === d).map(({ starts, ends }) => ({ starts, ends })),
    ),
  );
  const form = useForm<Settings>({ ...settings });
  const num = (key: keyof Omit<Settings, 'enabled'>, label: string, hint?: string) => (
    <Field label={label} hint={hint} error={form.errors[key]}>
      <Input
        type="number"
        min={0}
        value={form.data[key]}
        onChange={(e) => form.setData(key, Number(e.target.value))}
      />
    </Field>
  );
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Table settings')}</CardTitle>
        <CardDescription>
          {__(
            'Reservations by covers — how many guests you can seat at once. Add the “Book online” section in table mode to your website.',
          )}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form
          aria-label={__('Table settings')}
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            form.transform((d) => ({
              ...d,
              hours: days.flatMap((s, weekday) => s.map((x) => ({ weekday, ...x }))),
            }));
            form.put(`${base()}/settings`, { preserveScroll: true });
          }}
        >
          <label className="flex items-center gap-2 text-sm font-medium">
            <Checkbox
              checked={form.data.enabled}
              onCheckedChange={(on) => form.setData('enabled', on === true)}
            />
            {__('Take table reservations online')}
          </label>
          <div className="grid gap-4 sm:grid-cols-3">
            {num('covers', 'Covers', 'Guests seated at once.')}
            {num('sitting_minutes', 'Sitting (minutes)', 'How long a table is kept.')}
            {num('max_party', 'Largest party online')}
            {num('notice_minutes', 'Notice (minutes)')}
            <Field label={__('Times every')} error={form.errors.step_minutes}>
              <select
                className="border-border bg-bg h-10 w-full rounded-md border px-3 text-sm"
                value={form.data.step_minutes}
                onChange={(e) => form.setData('step_minutes', Number(e.target.value))}
              >
                {[15, 30, 60].map((m) => (
                  <option key={m} value={m}>
                    {m} {__('minutes')}
                  </option>
                ))}
              </select>
            </Field>
          </div>
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium">
              {__('Arrival times (first and last arrival)')}
            </legend>
            {DAYS.map((day, d) => {
              const stretches = days[d] ?? [];
              const set = (v: typeof stretches) => setDays(days.map((x, i) => (i === d ? v : x)));
              return (
                <div key={day} className="flex flex-wrap items-center gap-2 text-sm">
                  <label className="flex w-32 items-center gap-2">
                    <Checkbox
                      checked={stretches.length > 0}
                      onCheckedChange={(on) => set(on ? [{ starts: '18:00', ends: '21:00' }] : [])}
                    />
                    {day}
                  </label>
                  {stretches.map((s, i) => (
                    <span key={i} className="flex items-center gap-1">
                      <Input
                        aria-label={`${day} first arrival ${i + 1}`}
                        type="time"
                        step={300}
                        className="w-28"
                        value={s.starts}
                        onChange={(e) =>
                          set(
                            stretches.map((x, j) =>
                              j === i ? { ...x, starts: e.target.value } : x,
                            ),
                          )
                        }
                      />
                      <span aria-hidden>–</span>
                      <Input
                        aria-label={`${day} last arrival ${i + 1}`}
                        type="time"
                        step={300}
                        className="w-28"
                        value={s.ends}
                        onChange={(e) =>
                          set(
                            stretches.map((x, j) => (j === i ? { ...x, ends: e.target.value } : x)),
                          )
                        }
                      />
                    </span>
                  ))}
                  {stretches.length === 1 && (
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      aria-label={`Add lunch and dinner on ${day}`}
                      onClick={() => set([{ starts: '12:00', ends: '14:00' }, ...stretches])}
                    >
                      {__('Add lunch and dinner')}
                    </Button>
                  )}
                </div>
              );
            })}
          </fieldset>
          <Button type="submit" className="justify-self-start" loading={form.processing}>
            {__('Save table settings')}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

export default function Tables({ date, timezone, reservations, settings, hours }: Props) {
  const add = useForm({ date, time: '19:00', party: 2, name: '', phone: '', notes: '' });
  const go = (d: string) => router.get(base(), { date: d }, { preserveState: false });
  const seated = reservations
    .filter((r) => r.status !== 'no_show')
    .reduce((n, r) => n + r.party, 0);
  const label = new Date(`${date}T12:00:00Z`).toLocaleDateString('en-ZA', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
  });
  const set = (id: string, status: string) =>
    router.post(`${base()}/${id}/status`, { status }, { preserveScroll: true });

  return (
    <AppShell title={__('Tables')}>
      <Head title={__('Tables')} />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title={__('Tables')}
          description={`${label} · ${seated} guest${seated === 1 ? '' : 's'} booked · ${settings.covers} covers`}
          actions={
            <div className="flex gap-2">
              <Button
                variant="secondary"
                size="sm"
                aria-label={__('Previous day')}
                onClick={() => go(shift(date, -1))}
              >
                ‹
              </Button>
              <Button
                variant="secondary"
                size="sm"
                onClick={() => go(new Date().toLocaleDateString('en-CA', { timeZone: timezone }))}
              >
                {__('Today')}
              </Button>
              <Button
                variant="secondary"
                size="sm"
                aria-label={__('Next day')}
                onClick={() => go(shift(date, 1))}
              >
                ›
              </Button>
            </div>
          }
        />
        <section aria-label={__('Reservations')} className="grid gap-2">
          {reservations.length === 0 ? (
            <p className="text-fg-muted text-sm">{__('No reservations for this day.')}</p>
          ) : (
            <ul className="grid gap-2">
              {reservations.map((r) => (
                <li
                  key={r.id}
                  className="border-border flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                >
                  <div>
                    <p className="font-medium">
                      {r.time} · {r.customer} · {r.party} {__('guest')}
                      {r.party === 1 ? '' : 's'}
                    </p>
                    <p className="text-fg-muted text-sm">
                      {[r.phone, r.notes].filter(Boolean).join(' · ')}
                      {r.source === 'website' && (
                        <Badge tone="info" className="ml-2">
                          {__('Booked online')}
                        </Badge>
                      )}
                    </p>
                  </div>
                  {r.status === 'confirmed' ? (
                    <span className="flex gap-1">
                      <Button
                        size="sm"
                        variant="secondary"
                        aria-label={`Seated: ${r.customer} at ${r.time}`}
                        onClick={() => set(r.id, 'seated')}
                      >
                        {__('Seated')}
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        aria-label={`No-show: ${r.customer} at ${r.time}`}
                        onClick={() => set(r.id, 'no_show')}
                      >
                        {__('No-show')}
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        aria-label={`Cancel: ${r.customer} at ${r.time}`}
                        onClick={() => set(r.id, 'cancelled')}
                      >
                        {__('Cancel')}
                      </Button>
                    </span>
                  ) : (
                    <Badge tone="neutral">{r.status === 'seated' ? 'Seated' : 'No-show'}</Badge>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
        <Card>
          <CardHeader>
            <CardTitle>{__('Add a reservation')}</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              aria-label={__('Add a reservation')}
              className="grid gap-4 sm:grid-cols-3"
              onSubmit={(e) => {
                e.preventDefault();
                add.post(base(), {
                  preserveScroll: true,
                  onSuccess: () => add.reset('name', 'phone', 'notes'),
                });
              }}
            >
              <Field label={__('Date')} error={add.errors.date}>
                <Input
                  type="date"
                  value={add.data.date}
                  onChange={(e) => add.setData('date', e.target.value)}
                />
              </Field>
              <Field label={__('Time')} error={add.errors.time}>
                <Input
                  type="time"
                  step={300}
                  value={add.data.time}
                  onChange={(e) => add.setData('time', e.target.value)}
                />
              </Field>
              <Field label={__('Guests')} error={add.errors.party}>
                <Input
                  type="number"
                  min={1}
                  value={add.data.party}
                  onChange={(e) => add.setData('party', Number(e.target.value))}
                />
              </Field>
              <Field label={__('Name')} error={add.errors.name}>
                <Input
                  value={add.data.name}
                  maxLength={120}
                  onChange={(e) => add.setData('name', e.target.value)}
                />
              </Field>
              <Field label={__('Phone (optional)')} error={add.errors.phone}>
                <Input
                  type="tel"
                  value={add.data.phone}
                  onChange={(e) => add.setData('phone', e.target.value)}
                />
              </Field>
              <Field label={__('Notes (optional)')} error={add.errors.notes}>
                <Input
                  value={add.data.notes}
                  maxLength={1000}
                  onChange={(e) => add.setData('notes', e.target.value)}
                />
              </Field>
              <Button type="submit" className="justify-self-start" loading={add.processing}>
                {__('Add reservation')}
              </Button>
            </form>
          </CardContent>
        </Card>
        <SettingsCard settings={settings} hours={hours} />
      </div>
    </AppShell>
  );
}
