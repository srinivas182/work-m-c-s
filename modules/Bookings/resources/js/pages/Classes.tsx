import { Head, router, useForm } from '@inertiajs/react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/design-system/components/card';
import { EmptyState, PageHeader } from '@/design-system/components/feedback';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import AppShell from '@/layouts/AppShell';
import { CalendarDays } from 'lucide-react';
import { __, currencySymbol } from '@/i18n';

interface Attendee {
  id: string;
  name: string;
  phone: string | null;
  status: string;
}
interface ClassRow {
  id: string;
  title: string;
  status: string;
  online: boolean;
  capacity: number;
  paid: boolean;
  when: string;
  minutes: number;
  attendees: Attendee[];
}
interface Props {
  timezone: string;
  team: { id: string; name: string }[];
  canGoOnline: boolean;
  canTakePayments: boolean;
  classes: ClassRow[];
}

const base = () => window.location.pathname.replace(/\/$/, '');
const selectClass = 'h-10 w-full rounded-md border border-border bg-bg px-3 text-sm';

function ClassCard({ c }: { c: ClassRow }) {
  const add = useForm({ name: '', phone: '' });
  const taken = c.attendees.filter((a) => a.status !== 'cancelled').length;
  const mark = (id: string, status: string) =>
    router.post(`${base()}/attendees/${id}/status`, { status }, { preserveScroll: true });
  const live = c.status === 'scheduled';
  return (
    <Card>
      <CardHeader>
        <CardTitle>
          {c.title} {c.online && <Badge tone="info">{__('Online')}</Badge>}{' '}
          {c.paid && <Badge tone="neutral">{__('Paid online')}</Badge>}{' '}
          {!live && <Badge tone="neutral">{__('Cancelled')}</Badge>}
        </CardTitle>
        <CardDescription>
          {c.when} · {c.minutes} {__('min ·')} {taken} {__('of')} {c.capacity} {__('places taken')}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        {c.attendees.length === 0 ? (
          <p className="text-fg-muted text-sm">{__('No one has booked yet.')}</p>
        ) : (
          <ul className="grid gap-2" aria-label={`Coming to ${c.title}`}>
            {c.attendees.map((a) => (
              <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span>
                  {a.name}
                  {a.phone ? ` · ${a.phone}` : ''}
                </span>
                {a.status === 'confirmed' && live ? (
                  <span className="flex gap-1">
                    <Button
                      size="sm"
                      variant="secondary"
                      aria-label={`Attended: ${a.name}`}
                      onClick={() => mark(a.id, 'attended')}
                    >
                      {__('Attended')}
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      aria-label={`No-show: ${a.name}`}
                      onClick={() => mark(a.id, 'no_show')}
                    >
                      {__('No-show')}
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      aria-label={`Cancel ${a.name}’s place`}
                      onClick={() => mark(a.id, 'cancelled')}
                    >
                      {__('Cancel')}
                    </Button>
                  </span>
                ) : (
                  <Badge tone="neutral">
                    {{ attended: 'Attended', no_show: 'No-show', confirmed: 'Booked' }[a.status] ??
                      a.status}
                  </Badge>
                )}
              </li>
            ))}
          </ul>
        )}
        {live && (
          <div className="flex flex-wrap items-end gap-2">
            <form
              aria-label={`Add someone to ${c.title}`}
              className="flex flex-wrap items-end gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                add.post(`${base()}/${c.id}/attendees`, {
                  preserveScroll: true,
                  onSuccess: () => add.reset(),
                });
              }}
            >
              <Field label={__('Name')} error={add.errors.name}>
                <Input
                  value={add.data.name}
                  maxLength={120}
                  onChange={(e) => add.setData('name', e.target.value)}
                />
              </Field>
              <Field label={__('Phone (optional)')}>
                <Input
                  type="tel"
                  value={add.data.phone}
                  onChange={(e) => add.setData('phone', e.target.value)}
                />
              </Field>
              <Button type="submit" variant="secondary" loading={add.processing}>
                {__('Add')}
              </Button>
            </form>
            <Button
              variant="ghost"
              onClick={() => router.post(`${base()}/${c.id}/cancel`, {}, { preserveScroll: true })}
              aria-label={`Cancel class: ${c.title}`}
            >
              {__('Cancel class')}
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

export default function Classes({ team, canGoOnline, canTakePayments, classes }: Props) {
  const form = useForm({
    title: '',
    description: '',
    date: '',
    time: '18:00',
    minutes: 60,
    capacity: 12,
    location_type: 'in_person',
    member: '',
    price_cents: 0,
    pay_online: false,
  });
  return (
    <AppShell title={__('Classes')}>
      <Head title={__('Classes')} />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title={__('Classes')}
          description={__(
            'Group classes, in person or online — people book a place on your website.',
          )}
        />
        {classes.length === 0 ? (
          <EmptyState
            icon={CalendarDays}
            title={__('No classes scheduled')}
            description={__('Schedule your first class below.')}
          />
        ) : (
          classes.map((c) => <ClassCard key={c.id} c={c} />)
        )}
        <Card>
          <CardHeader>
            <CardTitle>{__('Schedule a class')}</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              aria-label={__('Schedule a class')}
              className="grid gap-4 sm:grid-cols-2"
              onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({ ...d, member: d.member || null }));
                form.post(base(), {
                  preserveScroll: true,
                  onSuccess: () => form.reset('title', 'description'),
                });
              }}
            >
              <Field label={__('Class name')} error={form.errors.title}>
                <Input
                  value={form.data.title}
                  maxLength={120}
                  onChange={(e) => form.setData('title', e.target.value)}
                />
              </Field>
              <Field label={__('Taught by')} error={form.errors.member}>
                <select
                  className={selectClass}
                  value={form.data.member}
                  onChange={(e) => form.setData('member', e.target.value)}
                >
                  <option value="">{__('Not shown')}</option>
                  {team.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label={__('Date')} error={form.errors.date}>
                <Input
                  type="date"
                  value={form.data.date}
                  onChange={(e) => form.setData('date', e.target.value)}
                />
              </Field>
              <Field label={__('Time')} error={form.errors.time}>
                <Input
                  type="time"
                  step={300}
                  value={form.data.time}
                  onChange={(e) => form.setData('time', e.target.value)}
                />
              </Field>
              <Field label={__('Length (minutes)')} error={form.errors.minutes}>
                <Input
                  type="number"
                  min={10}
                  value={form.data.minutes}
                  onChange={(e) => form.setData('minutes', Number(e.target.value))}
                />
              </Field>
              <Field label={__('Places')} error={form.errors.capacity}>
                <Input
                  type="number"
                  min={1}
                  value={form.data.capacity}
                  onChange={(e) => form.setData('capacity', Number(e.target.value))}
                />
              </Field>
              <Field
                label={__('Where')}
                hint={
                  canGoOnline
                    ? undefined
                    : 'Online classes need Video calls set up in Booking settings.'
                }
                error={form.errors.location_type}
              >
                <select
                  className={selectClass}
                  value={form.data.location_type}
                  onChange={(e) => form.setData('location_type', e.target.value)}
                >
                  <option value="in_person">{__('In person')}</option>
                  <option value="online">{__('Online')}</option>
                </select>
              </Field>
              <Field
                label={__('Price (:currency)', { currency: currencySymbol() })}
                hint={__('Shown to people booking.')}
                error={form.errors.price_cents}
              >
                <Input
                  type="number"
                  min={0}
                  step={0.01}
                  value={form.data.price_cents / 100}
                  onChange={(e) =>
                    form.setData('price_cents', Math.round(Number(e.target.value) * 100))
                  }
                />
              </Field>
              <label
                className={`flex items-start gap-2 text-sm sm:col-span-2 ${canTakePayments ? '' : 'text-fg-muted'}`}
              >
                <input
                  type="checkbox"
                  className="mt-1"
                  disabled={!canTakePayments}
                  checked={form.data.pay_online}
                  onChange={(e) => form.setData('pay_online', e.target.checked)}
                />
                <span>
                  <strong>{__('Paid online when booking')}</strong> —{' '}
                  {canTakePayments
                    ? 'the price goes straight to your bank; places are held while people pay.'
                    : 'connect your bank in Booking settings first.'}
                  {form.errors.pay_online && (
                    <span className="text-danger block">{form.errors.pay_online}</span>
                  )}
                </span>
              </label>
              <Button type="submit" className="justify-self-start" loading={form.processing}>
                {__('Schedule class')}
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
