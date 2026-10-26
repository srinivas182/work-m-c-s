import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Checkbox,
  EmptyState,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';
import { CalendarClock } from 'lucide-react';

interface Service {
  id: string;
  name: string;
  description: string | null;
  duration_minutes: number;
  buffer_before_minutes: number;
  buffer_after_minutes: number;
  price_cents: number;
  deposit_type: 'none' | 'fixed' | 'percent' | 'full';
  deposit_when: 'always' | 'online';
  location_type: 'in_person' | 'online' | 'either';
  deposit_value: number;
  active: boolean;
  members: string[];
}
interface Props {
  services: Service[];
  team: { id: string; name: string }[];
  hours: { member_id: string; weekday: number; starts: string; ends: string }[];
  timeOff: {
    id: string;
    member_id: string | null;
    starts_at: string;
    ends_at: string;
    reason: string | null;
  }[];
  timezone: string;
  payouts: { label: string | null };
  banks?: { code: string; name: string }[];
  policy: { hours: number; text: string; default: string };
  video: {
    connection: {
      provider: 'google' | 'zoom' | 'fixed';
      account_label: string | null;
      fixed_url: string | null;
    } | null;
    available: { google: boolean; zoom: boolean };
  };
  messages: {
    channel: 'email' | 'sms' | 'whatsapp';
    remind_24h: boolean;
    remind_2h: boolean;
    available: { sms: boolean; whatsapp: boolean };
  };
}

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const base = () => window.location.pathname.replace(/\/settings$/, '');
const selectClass = 'h-10 w-full rounded-md border border-border bg-bg px-3 text-sm';

function ServiceForm({
  service,
  team,
  onDone,
}: {
  service: Service | null;
  team: Props['team'];
  onDone: () => void;
}) {
  const form = useForm({
    name: service?.name ?? '',
    description: service?.description ?? '',
    duration_minutes: service?.duration_minutes ?? 60,
    buffer_before_minutes: service?.buffer_before_minutes ?? 0,
    buffer_after_minutes: service?.buffer_after_minutes ?? 0,
    price: service ? service.price_cents / 100 : 0,
    deposit_type: service?.deposit_type ?? 'none',
    deposit_when: service?.deposit_when ?? 'always',
    location_type: service?.location_type ?? 'in_person',
    deposit_value: service?.deposit_value ?? 0,
    active: service?.active ?? true,
    members: service?.members ?? team.map((m) => m.id),
  });
  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    form.transform(({ price, ...d }) => ({ ...d, price_cents: Math.round(Number(price) * 100) }));
    const options = { preserveScroll: true, onSuccess: onDone };
    if (service) form.put(`${base()}/services/${service.id}`, options);
    else form.post(`${base()}/services`, options);
  };
  const minutes = (
    key: 'duration_minutes' | 'buffer_before_minutes' | 'buffer_after_minutes',
    label: string,
    hint?: string,
  ) => (
    <Field label={label} hint={hint} error={form.errors[key]}>
      <Input
        type="number"
        min={key === 'duration_minutes' ? 5 : 0}
        step={5}
        value={form.data[key]}
        onChange={(e) => form.setData(key, Number(e.target.value))}
      />
    </Field>
  );
  return (
    <form
      onSubmit={submit}
      className="border-border grid gap-4 rounded-lg border p-4"
      aria-label={service ? `Edit ${service.name}` : 'Add a service'}
    >
      <Field label="Name" error={form.errors.name}>
        <Input
          value={form.data.name}
          maxLength={120}
          onChange={(e) => form.setData('name', e.target.value)}
        />
      </Field>
      <div className="grid gap-4 sm:grid-cols-3">
        {minutes('duration_minutes', 'Length (minutes)', 'In steps of 5.')}
        {minutes('buffer_before_minutes', 'Buffer before (minutes)', 'Preparation time.')}
        {minutes('buffer_after_minutes', 'Buffer after (minutes)', 'Clean-up time.')}
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          label="Where"
          error={(form.errors as Record<string, string | undefined>).location_type}
        >
          <select
            className={selectClass}
            value={form.data.location_type}
            onChange={(e) =>
              form.setData('location_type', e.target.value as Service['location_type'])
            }
          >
            <option value="in_person">In person</option>
            <option value="online">Online</option>
            <option value="either">The client chooses</option>
          </select>
        </Field>
        <Field
          label="Deposit"
          hint="Paid when booking online (once your bank is connected)."
          error={form.errors.deposit_type}
        >
          <select
            className={selectClass}
            value={form.data.deposit_type}
            onChange={(e) =>
              form.setData('deposit_type', e.target.value as Service['deposit_type'])
            }
          >
            <option value="none">No deposit</option>
            <option value="fixed">A fixed amount</option>
            <option value="percent">A percentage of the price</option>
            <option value="full">The full price</option>
          </select>
        </Field>
        {form.data.location_type === 'either' && form.data.deposit_type !== 'none' && (
          <Field
            label="Charge it"
            error={(form.errors as Record<string, string | undefined>).deposit_when}
          >
            <select
              className={selectClass}
              value={form.data.deposit_when}
              onChange={(e) =>
                form.setData('deposit_when', e.target.value as Service['deposit_when'])
              }
            >
              <option value="always">Always</option>
              <option value="online">Only for online appointments</option>
            </select>
          </Field>
        )}
        {form.data.deposit_type !== 'none' && form.data.deposit_type !== 'full' && (
          <Field
            label={form.data.deposit_type === 'percent' ? 'Deposit (%)' : 'Deposit (R)'}
            error={form.errors.deposit_value}
          >
            <Input
              type="number"
              min={0}
              step={form.data.deposit_type === 'percent' ? 1 : 0.01}
              value={
                form.data.deposit_type === 'percent'
                  ? form.data.deposit_value
                  : form.data.deposit_value / 100
              }
              onChange={(e) =>
                form.setData(
                  'deposit_value',
                  form.data.deposit_type === 'percent'
                    ? Number(e.target.value)
                    : Math.round(Number(e.target.value) * 100),
                )
              }
            />
          </Field>
        )}
      </div>
      <Field
        label="Price (R)"
        hint="0 shows “Ask us”."
        error={(form.errors as Record<string, string | undefined>).price_cents}
      >
        <Input
          type="number"
          min={0}
          step="0.01"
          value={form.data.price}
          onChange={(e) => form.setData('price', Number(e.target.value))}
        />
      </Field>
      <fieldset className="grid gap-2">
        <legend className="mb-1 text-sm font-medium">Who does it</legend>
        {team.map((m) => (
          <label key={m.id} className="flex items-center gap-2 text-sm">
            <Checkbox
              checked={form.data.members.includes(m.id)}
              onCheckedChange={(on) =>
                form.setData(
                  'members',
                  on ? [...form.data.members, m.id] : form.data.members.filter((id) => id !== m.id),
                )
              }
            />
            {m.name}
          </label>
        ))}
        {form.errors.members && <p className="text-danger-11 text-sm">{form.errors.members}</p>}
      </fieldset>
      <div className="flex gap-2">
        <Button type="submit" loading={form.processing}>
          Save service
        </Button>
        <Button type="button" variant="ghost" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

function HoursEditor({ member, hours }: { member: Props['team'][number]; hours: Props['hours'] }) {
  // Up to two stretches a day; the gap between them is a break.
  const initial = DAYS.map((_, d) =>
    hours
      .filter((h) => h.member_id === member.id && h.weekday === d)
      .map((h) => ({ starts: h.starts, ends: h.ends })),
  );
  const [days, setDays] = useState(initial);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = (d: number, value: { starts: string; ends: string }[]) =>
    setDays(days.map((v, i) => (i === d ? value : v)));
  const save = () => {
    setSaving(true);
    router.put(
      `${base()}/hours/${member.id}`,
      { hours: days.flatMap((stretches, weekday) => stretches.map((s) => ({ weekday, ...s }))) },
      {
        preserveScroll: true,
        onFinish: () => setSaving(false),
        onError: (e) => setErrors(e),
        onSuccess: () => setErrors({}),
      },
    );
  };
  return (
    <section aria-label={`${member.name}’s hours`} className="grid gap-3">
      <h3 className="font-medium">{member.name}</h3>
      {DAYS.map((day, d) => {
        const stretches = days[d] ?? [];
        return (
          <div key={day} className="grid gap-2 sm:grid-cols-[8rem_1fr] sm:items-center">
            <label className="flex items-center gap-2 text-sm">
              <Checkbox
                checked={stretches.length > 0}
                onCheckedChange={(on) => set(d, on ? [{ starts: '09:00', ends: '17:00' }] : [])}
              />
              {day}
            </label>
            <div className="flex flex-wrap items-center gap-2">
              {stretches.length === 0 && <span className="text-fg-muted text-sm">Not working</span>}
              {stretches.map((s, i) => (
                <span key={i} className="flex items-center gap-1">
                  <Input
                    aria-label={`${day} start ${i + 1}`}
                    type="time"
                    step={300}
                    className="w-28"
                    value={s.starts}
                    onChange={(e) =>
                      set(
                        d,
                        stretches.map((x, j) => (j === i ? { ...x, starts: e.target.value } : x)),
                      )
                    }
                  />
                  <span aria-hidden>–</span>
                  <Input
                    aria-label={`${day} end ${i + 1}`}
                    type="time"
                    step={300}
                    className="w-28"
                    value={s.ends}
                    onChange={(e) =>
                      set(
                        d,
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
                  aria-label={`Add a break on ${day}`}
                  onClick={() => set(d, [...stretches, { starts: '13:00', ends: '17:00' }])}
                >
                  Add a break
                </Button>
              )}
            </div>
          </div>
        );
      })}
      {Object.values(errors).length > 0 && (
        <p role="alert" className="text-danger-11 text-sm">
          {Object.values(errors)[0]}
        </p>
      )}
      <Button type="button" className="justify-self-start" loading={saving} onClick={save}>
        Save {member.name}’s hours
      </Button>
    </section>
  );
}

function VideoCard({ video }: Pick<Props, 'video'>) {
  const fixed = useForm({ url: video.connection?.fixed_url ?? '' });
  const c = video.connection;
  const name = { google: 'Google Meet', zoom: 'Zoom', fixed: 'Your meeting link' };
  return (
    <Card>
      <CardHeader>
        <CardTitle>Video calls</CardTitle>
        <CardDescription>
          For online appointments. Connect your own account and every appointment gets its own
          private link — the meetings are yours, not WURK’s.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {c ? (
          <div className="border-border flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3">
            <p>
              <strong>{name[c.provider]}</strong>
              {c.account_label ? ` — ${c.account_label}` : ''}
              {c.provider === 'fixed' ? ' — every client gets the same link.' : ' is connected.'}
            </p>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => router.delete(`${base()}/video`, { preserveScroll: true })}
            >
              Disconnect
            </Button>
          </div>
        ) : null}
        <div className="flex flex-wrap gap-2">
          {(['google', 'zoom'] as const).map((p) =>
            video.available[p] ? (
              <a
                key={p}
                href={`${base()}/video/connect/${p}`}
                className="border-border inline-flex h-10 items-center rounded-md border px-4 text-sm font-medium"
              >
                {c?.provider === p ? `Reconnect ${name[p]}` : `Connect ${name[p]}`}
              </a>
            ) : (
              <span key={p} className="text-fg-muted inline-flex h-10 items-center text-sm">
                {name[p]} — not switched on yet
              </span>
            ),
          )}
        </div>
        <form
          aria-label="Use a fixed meeting link"
          className="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end"
          onSubmit={(e) => {
            e.preventDefault();
            fixed.put(`${base()}/video/fixed`, { preserveScroll: true });
          }}
        >
          <Field
            label="Or use one meeting link for everyone"
            hint="Anyone given this link once can use it again — a connected account is safer."
            error={fixed.errors.url}
          >
            <Input
              type="url"
              placeholder="https://"
              value={fixed.data.url}
              onChange={(e) => fixed.setData('url', e.target.value)}
            />
          </Field>
          <Button type="submit" variant="secondary" loading={fixed.processing}>
            Save link
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function MessagesCard({ messages }: Pick<Props, 'messages'>) {
  const form = useForm({
    channel: messages.channel,
    remind_24h: messages.remind_24h,
    remind_2h: messages.remind_2h,
  });
  const options: {
    value: Props['messages']['channel'];
    label: string;
    note: string;
    on: boolean;
  }[] = [
    { value: 'email', label: 'Email', note: 'Free.', on: true },
    {
      value: 'sms',
      label: 'SMS',
      note: 'Uses WURK Credits. If credits run out, messages go by email instead.',
      on: messages.available.sms,
    },
    {
      value: 'whatsapp',
      label: 'WhatsApp',
      note: 'Uses WURK Credits. If credits run out, messages go by email instead.',
      on: messages.available.whatsapp,
    },
  ];
  return (
    <Card>
      <CardHeader>
        <CardTitle>Confirmations and reminders</CardTitle>
        <CardDescription>
          Customers get a confirmation, reminders, and a message if their booking moves or is
          cancelled — in WURK’s standard wording, with a link to change or cancel.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form
          aria-label="Confirmations and reminders"
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            form.put(`${base()}/messages`, { preserveScroll: true });
          }}
        >
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium">Send messages by</legend>
            {options.map((o) => (
              <label
                key={o.value}
                className={`flex items-start gap-2 text-sm ${o.on ? '' : 'text-fg-muted'}`}
              >
                <input
                  type="radio"
                  name="channel"
                  className="mt-1"
                  value={o.value}
                  disabled={!o.on}
                  checked={form.data.channel === o.value}
                  onChange={() => form.setData('channel', o.value)}
                />
                <span>
                  <strong>{o.label}</strong> — {o.on ? o.note : 'not switched on yet.'}
                </span>
              </label>
            ))}
          </fieldset>
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium">Reminders</legend>
            <label className="flex items-center gap-2 text-sm">
              <Checkbox
                checked={form.data.remind_24h}
                onCheckedChange={(on) => form.setData('remind_24h', on === true)}
              />
              24 hours before
            </label>
            <label className="flex items-center gap-2 text-sm">
              <Checkbox
                checked={form.data.remind_2h}
                onCheckedChange={(on) => form.setData('remind_2h', on === true)}
              />
              2 hours before
            </label>
          </fieldset>
          <Button
            type="submit"
            variant="secondary"
            className="justify-self-start"
            loading={form.processing}
          >
            Save message settings
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function DepositsCard({ payouts, banks, policy }: Pick<Props, 'payouts' | 'banks' | 'policy'>) {
  const connect = useForm({ bank_code: '', account_number: '' });
  const rules = useForm({ hours: policy.hours, text: policy.text });
  return (
    <Card>
      <CardHeader>
        <CardTitle>Deposits and cancellations</CardTitle>
        <CardDescription>
          Deposits are paid straight into your bank account through Paystack — WURK never holds your
          money. Set a deposit on each service.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-6">
        {payouts.label ? (
          <div className="border-border flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3">
            <p>
              Deposits are paid into <strong>{payouts.label}</strong>.
            </p>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => router.delete(`${base()}/payouts`, { preserveScroll: true })}
            >
              Disconnect
            </Button>
          </div>
        ) : (banks ?? []).length === 0 ? (
          <p className="text-fg-muted text-sm">
            Online payments aren’t switched on yet. Bookings work without deposits in the meantime.
          </p>
        ) : (
          <form
            aria-label="Connect your bank account"
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
              e.preventDefault();
              connect.put(`${base()}/payouts`, { preserveScroll: true });
            }}
          >
            <Field label="Bank" error={connect.errors.bank_code}>
              <select
                className={selectClass}
                value={connect.data.bank_code}
                onChange={(e) => connect.setData('bank_code', e.target.value)}
              >
                <option value="">Choose your bank</option>
                {(banks ?? []).map((b) => (
                  <option key={b.code} value={b.code}>
                    {b.name}
                  </option>
                ))}
              </select>
            </Field>
            <Field
              label="Account number"
              hint="Only the last four digits are kept."
              error={connect.errors.account_number}
            >
              <Input
                inputMode="numeric"
                autoComplete="off"
                value={connect.data.account_number}
                onChange={(e) => connect.setData('account_number', e.target.value)}
              />
            </Field>
            <Button type="submit" className="justify-self-start" loading={connect.processing}>
              Connect bank account
            </Button>
          </form>
        )}
        <form
          aria-label="Cancellation policy"
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            rules.put(`${base()}/policy`, { preserveScroll: true });
          }}
        >
          <Field
            label="Free cancellation until (hours before)"
            hint="Cancel in time: the deposit is refunded. Later, or a missed booking: it’s kept."
            error={rules.errors.hours}
          >
            <Input
              type="number"
              min={0}
              max={168}
              className="w-32"
              value={rules.data.hours}
              onChange={(e) => rules.setData('hours', Number(e.target.value))}
            />
          </Field>
          <Field
            label="Policy wording (optional)"
            hint="Shown to customers. Leave empty to use the standard wording."
            error={rules.errors.text}
          >
            <textarea
              className="border-border bg-bg min-h-24 w-full rounded-md border p-3 text-sm"
              placeholder={policy.default}
              maxLength={2000}
              value={rules.data.text}
              onChange={(e) => rules.setData('text', e.target.value)}
            />
          </Field>
          <Button
            type="submit"
            variant="secondary"
            className="justify-self-start"
            loading={rules.processing}
          >
            Save policy
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

export default function Settings({
  services,
  team,
  hours,
  timeOff,
  timezone,
  payouts,
  banks,
  policy,
  messages,
  video,
}: Props) {
  const [editing, setEditing] = useState<Service | 'new' | null>(
    services.length === 0 ? 'new' : null,
  );
  const off = useForm({ member_id: '', starts_at: '', ends_at: '', reason: '' });
  const who = (id: string | null) =>
    id === null ? 'Everyone' : (team.find((m) => m.id === id)?.name ?? 'A team member');
  const when = (iso: string) =>
    new Date(iso).toLocaleString('en-ZA', {
      timeZone: timezone,
      dateStyle: 'medium',
      timeStyle: 'short',
    });

  return (
    <AppShell title="Booking settings">
      <Head title="Booking settings" />
      <div className="grid max-w-4xl gap-8">
        <PageHeader
          title="Booking settings"
          description={`What customers can book, who does it and when. Times are in ${timezone}.`}
        />

        <Card>
          <CardHeader>
            <CardTitle>Services</CardTitle>
            <CardDescription>What customers can book online.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4">
            {services.length === 0 && editing !== 'new' && (
              <EmptyState
                icon={CalendarClock}
                title="No services yet"
                description="Add your first bookable service."
              />
            )}
            <ul className="grid gap-2">
              {services.map((s) =>
                editing !== 'new' && editing?.id === s.id ? (
                  <li key={s.id}>
                    <ServiceForm service={s} team={team} onDone={() => setEditing(null)} />
                  </li>
                ) : (
                  <li
                    key={s.id}
                    className="border-border flex items-center justify-between gap-3 rounded-lg border p-3"
                  >
                    <div>
                      <p className="font-medium">{s.name}</p>
                      <p className="text-fg-muted text-sm">
                        {s.duration_minutes} min
                        {s.buffer_after_minutes
                          ? ` + ${s.buffer_after_minutes} min after`
                          : ''} · {s.price_cents ? rand(s.price_cents) : 'Ask us'} ·{' '}
                        {s.members.map((id) => who(id)).join(', ')}
                      </p>
                    </div>
                    <Button
                      size="sm"
                      variant="secondary"
                      aria-label={`Edit ${s.name}`}
                      onClick={() => setEditing(s)}
                    >
                      Edit
                    </Button>
                  </li>
                ),
              )}
            </ul>
            {editing === 'new' ? (
              <ServiceForm service={null} team={team} onDone={() => setEditing(null)} />
            ) : (
              <Button
                variant="secondary"
                className="justify-self-start"
                onClick={() => setEditing('new')}
              >
                Add a service
              </Button>
            )}
          </CardContent>
        </Card>

        <DepositsCard payouts={payouts} banks={banks} policy={policy} />
        <MessagesCard messages={messages} />
        <VideoCard video={video} />

        <Card>
          <CardHeader>
            <CardTitle>Team hours</CardTitle>
            <CardDescription>
              When each person can be booked. Add a second stretch for a lunch break.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-8">
            {team.map((m) => (
              <HoursEditor key={m.id} member={m} hours={hours} />
            ))}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Time off</CardTitle>
            <CardDescription>
              Holidays, leave and public holidays. Nobody can book these times.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4">
            {timeOff.length > 0 && (
              <ul className="grid gap-2">
                {timeOff.map((t) => (
                  <li
                    key={t.id}
                    className="border-border flex items-center justify-between gap-3 rounded-lg border p-3 text-sm"
                  >
                    <span>
                      <strong>{who(t.member_id)}</strong> · {when(t.starts_at)} – {when(t.ends_at)}
                      {t.reason ? ` · ${t.reason}` : ''}
                    </span>
                    <Button
                      size="sm"
                      variant="ghost"
                      aria-label={`Remove time off: ${who(t.member_id)} ${when(t.starts_at)}`}
                      onClick={() =>
                        router.delete(`${base()}/time-off/${t.id}`, { preserveScroll: true })
                      }
                    >
                      Remove
                    </Button>
                  </li>
                ))}
              </ul>
            )}
            <form
              aria-label="Add time off"
              className="grid gap-4 sm:grid-cols-2"
              onSubmit={(e) => {
                e.preventDefault();
                off.transform((d) => ({
                  ...d,
                  member_id: d.member_id === '' ? null : d.member_id,
                }));
                off.post(`${base()}/time-off`, {
                  preserveScroll: true,
                  onSuccess: () => off.reset(),
                });
              }}
            >
              <Field label="Who" error={off.errors.member_id}>
                <select
                  className={selectClass}
                  value={off.data.member_id}
                  onChange={(e) => off.setData('member_id', e.target.value)}
                >
                  <option value="">Everyone (the business is closed)</option>
                  {team.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Reason (optional)" error={off.errors.reason}>
                <Input
                  value={off.data.reason}
                  maxLength={120}
                  onChange={(e) => off.setData('reason', e.target.value)}
                />
              </Field>
              <Field label="From" error={off.errors.starts_at}>
                <Input
                  type="datetime-local"
                  step={300}
                  value={off.data.starts_at}
                  onChange={(e) => off.setData('starts_at', e.target.value)}
                />
              </Field>
              <Field label="Until" error={off.errors.ends_at}>
                <Input
                  type="datetime-local"
                  step={300}
                  value={off.data.ends_at}
                  onChange={(e) => off.setData('ends_at', e.target.value)}
                />
              </Field>
              <Button type="submit" className="justify-self-start" loading={off.processing}>
                Add time off
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
