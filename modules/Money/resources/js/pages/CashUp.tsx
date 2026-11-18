import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

type Till = { cash: number; card_machine: number; qr: number };
interface Row {
  id: string;
  day: string;
  expected: Till;
  counted: Till;
  difference_cents: number;
  reason: string | null;
  status: 'locked' | 'reopened';
  by: string;
}
interface Props {
  day: string;
  expected: Till;
  till: Record<keyof Till, string>;
  cashUp: Row | null;
  recent: Row[];
  reasonAbove: number;
  isOwner: boolean;
}

/** Rand as WURK writes it everywhere: R1 380.00 (the server's own format). */
const rand = (c: number) => `R${(c / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ')}`;
const tenantBase = () => '/' + window.location.pathname.split('/')[1];

export default function CashUp({
  day,
  expected,
  till,
  cashUp,
  recent,
  reasonAbove,
  isOwner,
}: Props) {
  const warning = (usePage().props.flash as { warning?: string | null } | undefined)?.warning;
  const form = useForm({ day, cash: '', card_machine: '', qr: '', reason: '' });
  const diff =
    form.data.cash === '' ? null : Math.round(Number(form.data.cash) * 100) - expected.cash;
  const locked = cashUp?.status === 'locked';
  return (
    <AppShell title={__('Cash-up')}>
      <Head title={__('Cash-up')} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title={__('Cash-up')}
          description={__(
            'At the end of the day, count what’s in the till. WURK shows what should be there from the day’s payments and sales.',
          )}
        />
        {warning && (
          <p role="alert" className="text-danger text-sm">
            {warning}
          </p>
        )}
        <Card>
          <CardHeader>
            <CardTitle>
              <label className="flex flex-wrap items-center gap-2">
                {__('Day')}
                <Input
                  type="date"
                  className="w-auto"
                  value={day}
                  onChange={(e) =>
                    router.get(
                      `${tenantBase()}/money/cash-up`,
                      { day: e.target.value },
                      { preserveScroll: true },
                    )
                  }
                />
              </label>
            </CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4 text-sm">
            <dl className="grid grid-cols-3 gap-3">
              {(Object.keys(till) as (keyof Till)[]).map((k) => (
                <div key={k}>
                  <dt className="text-fg-muted">
                    {till[k]} {__('expected')}
                  </dt>
                  <dd className="text-lg font-semibold">{rand(expected[k])}</dd>
                </div>
              ))}
            </dl>
            {locked && cashUp ? (
              <div className="grid gap-2">
                <p>
                  {__('Locked by')} {cashUp.by}
                  {__(': cash counted')} {rand(cashUp.counted.cash)}
                  {cashUp.difference_cents === 0
                    ? ', it matches.'
                    : `, ${rand(Math.abs(cashUp.difference_cents))} ${cashUp.difference_cents < 0 ? 'short' : 'over'}.`}
                  {cashUp.reason ? ` Why: ${cashUp.reason}` : ''}
                </p>
                {isOwner && (
                  <Button
                    size="sm"
                    variant="secondary"
                    className="justify-self-start"
                    onClick={() => router.post(`${tenantBase()}/money/cash-up/${cashUp.id}/reopen`)}
                  >
                    {__('Reopen this day')}
                  </Button>
                )}
              </div>
            ) : (
              <form
                className="grid gap-3"
                onSubmit={(e) => {
                  e.preventDefault();
                  form.post(`${tenantBase()}/money/cash-up`, { preserveScroll: true });
                }}
              >
                <div className="grid gap-3 sm:grid-cols-3">
                  <Field label={__('Cash counted (R)')} error={form.errors.cash}>
                    <Input
                      inputMode="decimal"
                      value={form.data.cash}
                      onChange={(e) => form.setData('cash', e.target.value)}
                    />
                  </Field>
                  <Field label={__('Card machine slip (R)')} error={form.errors.card_machine}>
                    <Input
                      inputMode="decimal"
                      value={form.data.card_machine}
                      onChange={(e) => form.setData('card_machine', e.target.value)}
                    />
                  </Field>
                  <Field label={__('SnapScan / Zapper (R)')} error={form.errors.qr}>
                    <Input
                      inputMode="decimal"
                      value={form.data.qr}
                      onChange={(e) => form.setData('qr', e.target.value)}
                    />
                  </Field>
                </div>
                {diff !== null && diff !== 0 && (
                  <p
                    role="status"
                    className={Math.abs(diff) > reasonAbove ? 'text-danger' : 'text-fg-muted'}
                  >
                    {__('The cash is')} {rand(Math.abs(diff))} {diff < 0 ? 'short' : 'over'}.
                    {Math.abs(diff) > reasonAbove ? ' Say why before locking the day.' : ''}
                  </p>
                )}
                <Field label={__('Why it’s different (if it is)')} error={form.errors.reason}>
                  <Input
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                  />
                </Field>
                <Button type="submit" loading={form.processing} className="justify-self-start">
                  {__('Lock the day')}
                </Button>
              </form>
            )}
          </CardContent>
        </Card>
        {recent.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>{__('Recent cash-ups')}</CardTitle>
            </CardHeader>
            <CardContent>
              <ul className="grid gap-2 text-sm">
                {recent.map((r) => (
                  <li key={r.id} className="flex flex-wrap justify-between gap-2">
                    <span>
                      {r.day} · {r.by}
                    </span>
                    <span className={r.difference_cents < 0 ? 'text-danger' : 'text-fg-muted'}>
                      {r.difference_cents === 0
                        ? 'Matched'
                        : `${rand(Math.abs(r.difference_cents))} ${r.difference_cents < 0 ? 'short' : 'over'}`}
                      {r.status === 'reopened' ? ' · reopened' : ''}
                    </span>
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
