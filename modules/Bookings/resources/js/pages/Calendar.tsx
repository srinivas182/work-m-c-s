import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { EmptyState, PageHeader } from '@/design-system/components/feedback';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import AppShell from '@/layouts/AppShell';
import { CalendarDays } from 'lucide-react';
import { __, currencySymbol, money } from '@/i18n';

interface BookingRow {
  id: string;
  member_id: string;
  service: string;
  customer: string;
  phone: string | null;
  notes: string | null;
  status: string;
  source: string;
  /** Tapped Confirm on their WhatsApp reminder (21.5). */
  customer_confirmed?: boolean;
  starts_at: string;
  ends_at: string;
  price_cents: number;
  deposit_cents: number;
  deposit_paid: boolean;
  in_person_cents: number;
}
interface Props {
  view: 'day' | 'week';
  date: string;
  start: string;
  days: number;
  timezone: string;
  team: { id: string; name: string }[];
  services: { id: string; name: string; duration: number }[];
  bookings: BookingRow[];
  canManage: boolean;
}

const base = () => window.location.pathname.replace(/\/$/, '');
const selectClass = 'h-10 w-full rounded-md border border-border bg-bg px-3 text-sm';
const addDays = (iso: string, n: number) => {
  const d = new Date(`${iso}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
};
const hm = (iso: string) => iso.slice(11, 16); // local time as sent (with the business's offset)
const STATUS: Record<string, string> = {
  no_show: 'No-show',
  completed: 'Done',
  pending_payment: 'Awaiting deposit',
};

const rand = (cents: number) => money(cents); // the business's currency (25.1b)
const METHODS: [string, string][] = [
  ['cash', 'Cash'],
  ['card_machine', 'Card machine'],
  ['qr', 'SnapScan / Zapper at the counter'],
];

/** Money taken in person: how it was paid, and how much (the deposit or what's left of the price). */
function RecordPayment({ b, done }: { b: BookingRow; done: () => void }) {
  const paid = (b.deposit_paid ? b.deposit_cents : 0) + b.in_person_cents;
  const due = b.status === 'pending_payment' ? b.deposit_cents : Math.max(b.price_cents - paid, 0);
  const form = useForm({ method: 'cash', amount: due > 0 ? (due / 100).toFixed(2) : '' });
  return (
    <form
      aria-label={`Record a payment from ${b.customer}`}
      className="grid gap-2 sm:grid-cols-[1fr_8rem_auto] sm:items-end"
      onSubmit={(e) => {
        e.preventDefault();
        form.post(`${base()}/${b.id}/paid`, { preserveScroll: true, onSuccess: done });
      }}
    >
      <Field label={__('How they paid')} error={form.errors.method}>
        <select
          className={selectClass}
          value={form.data.method}
          onChange={(e) => form.setData('method', e.target.value)}
        >
          {METHODS.map(([k, label]) => (
            <option key={k} value={k}>
              {label}
            </option>
          ))}
        </select>
      </Field>
      <Field
        label={__('Amount (:currency)', { currency: currencySymbol() })}
        error={form.errors.amount}
      >
        <Input
          inputMode="decimal"
          value={form.data.amount}
          onChange={(e) => form.setData('amount', e.target.value)}
        />
      </Field>
      <Button type="submit" size="sm" loading={form.processing}>
        {__('Record')}
      </Button>
    </form>
  );
}

function BookingItem({
  b,
  team,
  canManage,
}: {
  b: BookingRow;
  team: Props['team'];
  canManage: boolean;
}) {
  const [moving, setMoving] = useState(false);
  const [paying, setPaying] = useState(false);
  const paid = (b.deposit_paid ? b.deposit_cents : 0) + b.in_person_cents;
  const move = useForm({
    date: b.starts_at.slice(0, 10),
    time: hm(b.starts_at),
    member: b.member_id,
  });
  const set = (status: string) =>
    router.post(`${base()}/${b.id}/status`, { status }, { preserveScroll: true });
  return (
    <li className="border-border grid gap-2 rounded-lg border p-3">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <p className="font-medium">
          {hm(b.starts_at)}–{hm(b.ends_at)} · {b.customer}
        </p>
        <span className="flex gap-1">
          {b.source === 'website' && <Badge tone="info">{__('Booked online')}</Badge>}
          {b.source === 'chat' && <Badge tone="info">{__('Booked by the AI assistant')}</Badge>}
          {b.source === 'whatsapp' && <Badge tone="info">{__('Booked on WhatsApp')}</Badge>}
          {b.customer_confirmed && <Badge tone="success">{__('Confirmed by the customer')}</Badge>}
          {STATUS[b.status] && <Badge tone="neutral">{STATUS[b.status]}</Badge>}
        </span>
      </div>
      <p className="text-fg-muted text-sm">
        {b.service}
        {b.phone ? ` · ${b.phone}` : ''}
        {b.notes ? ` · ${b.notes}` : ''}
        {paid > 0
          ? ` · Paid ${rand(paid)}${b.price_cents > 0 ? ` of ${rand(b.price_cents)}` : ''}`
          : ''}
      </p>
      {canManage && !moving && (
        <div className="flex flex-wrap gap-2">
          <Button
            size="sm"
            variant="secondary"
            aria-label={`Move ${b.customer}’s booking at ${hm(b.starts_at)}`}
            onClick={() => setMoving(true)}
          >
            {__('Move')}
          </Button>
          {['confirmed', 'pending_payment', 'completed'].includes(b.status) && (
            <Button
              size="sm"
              variant="ghost"
              aria-label={`Paid in person: ${b.customer} at ${hm(b.starts_at)}`}
              onClick={() => setPaying(!paying)}
            >
              {__('Paid in person')}
            </Button>
          )}
          {b.status === 'confirmed' && (
            <>
              <Button
                size="sm"
                variant="ghost"
                aria-label={`Done: ${b.customer} at ${hm(b.starts_at)}`}
                onClick={() => set('completed')}
              >
                {__('Done')}
              </Button>
              <Button
                size="sm"
                variant="ghost"
                aria-label={`No-show: ${b.customer} at ${hm(b.starts_at)}`}
                onClick={() => set('no_show')}
              >
                {__('No-show')}
              </Button>
              <Button
                size="sm"
                variant="ghost"
                aria-label={`Cancel: ${b.customer} at ${hm(b.starts_at)}`}
                onClick={() => set('cancelled')}
              >
                {__('Cancel')}
              </Button>
            </>
          )}
        </div>
      )}
      {paying && canManage && <RecordPayment b={b} done={() => setPaying(false)} />}
      {moving && (
        <form
          aria-label={`Move ${b.customer}’s booking`}
          className="grid gap-3 sm:grid-cols-4 sm:items-end"
          onSubmit={(e) => {
            e.preventDefault();
            move.put(`${base()}/${b.id}/move`, {
              preserveScroll: true,
              onSuccess: () => setMoving(false),
            });
          }}
        >
          <Field label={__('Date')} error={move.errors.date}>
            <Input
              type="date"
              value={move.data.date}
              onChange={(e) => move.setData('date', e.target.value)}
            />
          </Field>
          <Field label={__('Time')} error={move.errors.time}>
            <Input
              type="time"
              step={300}
              value={move.data.time}
              onChange={(e) => move.setData('time', e.target.value)}
            />
          </Field>
          <Field label={__('With')}>
            <select
              className={selectClass}
              value={move.data.member}
              onChange={(e) => move.setData('member', e.target.value)}
            >
              {team.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.name}
                </option>
              ))}
            </select>
          </Field>
          <span className="flex gap-2">
            <Button type="submit" size="sm" loading={move.processing}>
              {__('Move')}
            </Button>
            <Button type="button" size="sm" variant="ghost" onClick={() => setMoving(false)}>
              {__('Keep')}
            </Button>
          </span>
        </form>
      )}
    </li>
  );
}

export default function Calendar({
  view,
  date,
  start,
  days,
  timezone,
  team,
  services,
  bookings,
  canManage,
}: Props) {
  const nowLocal = new Date().toLocaleTimeString('en-ZA', {
    timeZone: timezone,
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  });
  const add = useForm({
    service: services[0]?.id ?? '',
    member: '',
    date,
    time: nowLocal.slice(0, 5),
    name: '',
    phone: '',
    email: '',
    notes: '',
  });
  const go = (d: string, v = view) =>
    router.get(base(), { date: d, view: v }, { preserveState: false });
  const step = view === 'week' ? 7 : 1;
  const dayLabel = (iso: string) =>
    new Date(`${iso}T12:00:00Z`).toLocaleDateString('en-ZA', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      timeZone: 'UTC',
    });
  const columns =
    view === 'day'
      ? team.map((m) => ({
          key: m.id,
          title: m.name,
          items: bookings.filter((b) => b.member_id === m.id),
        }))
      : Array.from({ length: days }, (_, i) => addDays(start, i)).map((d) => ({
          key: d,
          title: dayLabel(d),
          items: bookings.filter((b) => b.starts_at.slice(0, 10) === d),
        }));

  return (
    <AppShell title={__('Calendar')}>
      <Head title={__('Calendar')} />
      <div className="grid gap-6">
        <PageHeader
          title={__('Calendar')}
          description={view === 'day' ? dayLabel(start) : `Week of ${dayLabel(start)}`}
          actions={
            <div className="flex flex-wrap gap-2">
              <Button
                variant="secondary"
                size="sm"
                onClick={() => go(addDays(start, -step))}
                aria-label={view === 'day' ? 'Previous day' : 'Previous week'}
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
                onClick={() => go(addDays(start, step))}
                aria-label={view === 'day' ? 'Next day' : 'Next week'}
              >
                ›
              </Button>
              <Button
                variant={view === 'day' ? 'primary' : 'ghost'}
                size="sm"
                aria-pressed={view === 'day'}
                onClick={() => go(date, 'day')}
              >
                {__('Day')}
              </Button>
              <Button
                variant={view === 'week' ? 'primary' : 'ghost'}
                size="sm"
                aria-pressed={view === 'week'}
                onClick={() => go(date, 'week')}
              >
                {__('Week')}
              </Button>
            </div>
          }
        />

        {services.length === 0 && (
          <EmptyState
            icon={CalendarDays}
            title={__('No services to book yet')}
            description={__('Add your services and team hours in Booking settings first.')}
          />
        )}

        <div
          className={`grid gap-4 ${view === 'week' ? 'lg:grid-cols-7' : 'md:grid-cols-2 xl:grid-cols-3'}`}
        >
          {columns.map((c) => (
            <section key={c.key} aria-label={c.title} className="grid content-start gap-2">
              <h2 className="font-semibold">{c.title}</h2>
              {c.items.length === 0 ? (
                <p className="text-fg-muted text-sm">{__('Nothing booked.')}</p>
              ) : (
                <ul className="grid gap-2">
                  {c.items.map((b) => (
                    <BookingItem key={b.id} b={b} team={team} canManage={canManage} />
                  ))}
                </ul>
              )}
            </section>
          ))}
        </div>

        {canManage && services.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>{__('Add a booking')}</CardTitle>
            </CardHeader>
            <CardContent>
              <form
                aria-label={__('Add a booking')}
                className="grid gap-4 sm:grid-cols-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  add.transform((d) => ({ ...d, member: d.member === '' ? null : d.member }));
                  add.post(base(), {
                    preserveScroll: true,
                    onSuccess: () => add.reset('name', 'phone', 'email', 'notes'),
                  });
                }}
              >
                <Field label={__('Service')} error={add.errors.service}>
                  <select
                    className={selectClass}
                    value={add.data.service}
                    onChange={(e) => add.setData('service', e.target.value)}
                  >
                    {services.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.name} ({s.duration} {__('min)')}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label={__('With')} error={add.errors.member}>
                  <select
                    className={selectClass}
                    value={add.data.member}
                    onChange={(e) => add.setData('member', e.target.value)}
                  >
                    <option value="">{__('Anyone free')}</option>
                    {team.map((m) => (
                      <option key={m.id} value={m.id}>
                        {m.name}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label={__('Date')} error={add.errors.date}>
                  <Input
                    type="date"
                    value={add.data.date}
                    onChange={(e) => add.setData('date', e.target.value)}
                  />
                </Field>
                <Field
                  label={__('Time')}
                  hint={__('Walk-in? It’s set to now.')}
                  error={add.errors.time}
                >
                  <Input
                    type="time"
                    step={300}
                    value={add.data.time}
                    onChange={(e) => add.setData('time', e.target.value)}
                  />
                </Field>
                <Field label={__('Customer name')} error={add.errors.name}>
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
                <Field
                  label={__('Notes (optional)')}
                  error={add.errors.notes}
                  className="sm:col-span-2"
                >
                  <Input
                    value={add.data.notes}
                    maxLength={1000}
                    onChange={(e) => add.setData('notes', e.target.value)}
                  />
                </Field>
                <Button type="submit" className="justify-self-start" loading={add.processing}>
                  {__('Add booking')}
                </Button>
              </form>
            </CardContent>
          </Card>
        )}
        <p className="text-fg-muted text-sm">
          {__('Times are in')} {timezone}.{' '}
          <Link href={`${base()}/settings`}>{__('Booking settings')}</Link>
        </p>
      </div>
    </AppShell>
  );
}
