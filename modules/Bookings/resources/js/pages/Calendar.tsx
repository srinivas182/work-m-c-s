import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  EmptyState,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { CalendarDays } from 'lucide-react';

interface BookingRow {
  id: string;
  member_id: string;
  service: string;
  customer: string;
  phone: string | null;
  notes: string | null;
  status: string;
  source: string;
  starts_at: string;
  ends_at: string;
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
          {b.source === 'website' && <Badge tone="info">Booked online</Badge>}
          {STATUS[b.status] && <Badge tone="neutral">{STATUS[b.status]}</Badge>}
        </span>
      </div>
      <p className="text-fg-muted text-sm">
        {b.service}
        {b.phone ? ` · ${b.phone}` : ''}
        {b.notes ? ` · ${b.notes}` : ''}
      </p>
      {canManage && !moving && (
        <div className="flex flex-wrap gap-2">
          <Button
            size="sm"
            variant="secondary"
            aria-label={`Move ${b.customer}’s booking at ${hm(b.starts_at)}`}
            onClick={() => setMoving(true)}
          >
            Move
          </Button>
          {b.status === 'confirmed' && (
            <>
              <Button
                size="sm"
                variant="ghost"
                aria-label={`Done: ${b.customer} at ${hm(b.starts_at)}`}
                onClick={() => set('completed')}
              >
                Done
              </Button>
              <Button
                size="sm"
                variant="ghost"
                aria-label={`No-show: ${b.customer} at ${hm(b.starts_at)}`}
                onClick={() => set('no_show')}
              >
                No-show
              </Button>
              <Button
                size="sm"
                variant="ghost"
                aria-label={`Cancel: ${b.customer} at ${hm(b.starts_at)}`}
                onClick={() => set('cancelled')}
              >
                Cancel
              </Button>
            </>
          )}
        </div>
      )}
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
          <Field label="Date" error={move.errors.date}>
            <Input
              type="date"
              value={move.data.date}
              onChange={(e) => move.setData('date', e.target.value)}
            />
          </Field>
          <Field label="Time" error={move.errors.time}>
            <Input
              type="time"
              step={300}
              value={move.data.time}
              onChange={(e) => move.setData('time', e.target.value)}
            />
          </Field>
          <Field label="With">
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
              Move
            </Button>
            <Button type="button" size="sm" variant="ghost" onClick={() => setMoving(false)}>
              Keep
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
    <AppShell title="Calendar">
      <Head title="Calendar" />
      <div className="grid gap-6">
        <PageHeader
          title="Calendar"
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
                Today
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
                Day
              </Button>
              <Button
                variant={view === 'week' ? 'primary' : 'ghost'}
                size="sm"
                aria-pressed={view === 'week'}
                onClick={() => go(date, 'week')}
              >
                Week
              </Button>
            </div>
          }
        />

        {services.length === 0 && (
          <EmptyState
            icon={CalendarDays}
            title="No services to book yet"
            description="Add your services and team hours in Booking settings first."
          />
        )}

        <div
          className={`grid gap-4 ${view === 'week' ? 'lg:grid-cols-7' : 'md:grid-cols-2 xl:grid-cols-3'}`}
        >
          {columns.map((c) => (
            <section key={c.key} aria-label={c.title} className="grid content-start gap-2">
              <h2 className="font-semibold">{c.title}</h2>
              {c.items.length === 0 ? (
                <p className="text-fg-muted text-sm">Nothing booked.</p>
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
              <CardTitle>Add a booking</CardTitle>
            </CardHeader>
            <CardContent>
              <form
                aria-label="Add a booking"
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
                <Field label="Service" error={add.errors.service}>
                  <select
                    className={selectClass}
                    value={add.data.service}
                    onChange={(e) => add.setData('service', e.target.value)}
                  >
                    {services.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.name} ({s.duration} min)
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="With" error={add.errors.member}>
                  <select
                    className={selectClass}
                    value={add.data.member}
                    onChange={(e) => add.setData('member', e.target.value)}
                  >
                    <option value="">Anyone free</option>
                    {team.map((m) => (
                      <option key={m.id} value={m.id}>
                        {m.name}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="Date" error={add.errors.date}>
                  <Input
                    type="date"
                    value={add.data.date}
                    onChange={(e) => add.setData('date', e.target.value)}
                  />
                </Field>
                <Field label="Time" hint="Walk-in? It’s set to now." error={add.errors.time}>
                  <Input
                    type="time"
                    step={300}
                    value={add.data.time}
                    onChange={(e) => add.setData('time', e.target.value)}
                  />
                </Field>
                <Field label="Customer name" error={add.errors.name}>
                  <Input
                    value={add.data.name}
                    maxLength={120}
                    onChange={(e) => add.setData('name', e.target.value)}
                  />
                </Field>
                <Field label="Phone (optional)" error={add.errors.phone}>
                  <Input
                    type="tel"
                    value={add.data.phone}
                    onChange={(e) => add.setData('phone', e.target.value)}
                  />
                </Field>
                <Field label="Notes (optional)" error={add.errors.notes} className="sm:col-span-2">
                  <Input
                    value={add.data.notes}
                    maxLength={1000}
                    onChange={(e) => add.setData('notes', e.target.value)}
                  />
                </Field>
                <Button type="submit" className="justify-self-start" loading={add.processing}>
                  Add booking
                </Button>
              </form>
            </CardContent>
          </Card>
        )}
        <p className="text-fg-muted text-sm">
          Times are in {timezone}. <Link href={`${base()}/settings`}>Booking settings</Link>
        </p>
      </div>
    </AppShell>
  );
}
