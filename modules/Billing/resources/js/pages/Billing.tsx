import { Head, router, useForm } from '@inertiajs/react';
import { CreditCard, Receipt } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import {
  Badge,
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system';
import { HelpTip } from '@/components/HelpTip';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';
import { __ } from '@/i18n';

interface Props {
  credits: {
    auto_top_up: boolean;
    pack_id: string | null;
    cap_cents: number | null;
    usage_summary: boolean;
    spent_cents: number;
  };
  subscription: {
    package_id: string;
    pending: { package: string; period: string } | null;
    package: string;
    status: string;
    period: string;
    period_total_cents: number;
    trial_ends_at: string | null;
    current_period_end: string | null;
  } | null;
  needsPayment: boolean;
  vatPercent: number;
  account: {
    billing_email: string;
    billing_name: string;
    vat_number: string | null;
    saved: boolean;
  };
  card: { brand: string | null; last4: string | null; expires: string | null } | null;
  packs: { id: string; name: string; credits: number; total_cents: number }[];
  creditPacksAllowed: boolean;
  payments: {
    id: string;
    at: string;
    purpose: string;
    total_cents: number;
    status: string;
    failure: string | null;
  }[];
  gatewayReady: boolean;
  payMethods: { provider: string; name: string }[];
  wurkEft: string | null;
  reference: string;
  stage: 'none' | 'grace' | 'restricted' | 'suspended';
  /** Pay as you go: the plan is paid day by day from credits (Sprint 22.5). */
  prepaid: {
    daily_milli: number;
    days_left: number;
    ran_out_on: string | null;
    grace_days: number;
  } | null;
  minTopUp: number;
  packagesOnSale: {
    id: string;
    name: string;
    monthly_total_cents: number;
    yearly_total_cents: number;
  }[];
  invoices: {
    id: string;
    number: string;
    issued_at: string;
    total_cents: number;
    credited_cents: number;
  }[];
  creditNotes: { id: string; number: string; total_cents: number; issued_at: string }[];
  debitOrders: {
    available: boolean;
    simulated: boolean;
    banks: Record<string, string>;
    accountTypes: string[];
    method: 'card' | 'debit_order';
    mandate: {
      status: string;
      bank: string;
      account: string;
      day: number;
      failure: string | null;
    } | null;
  };
}

const date = (iso: string | null) =>
  iso
    ? new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      })
    : '—';
const PURPOSE: Record<string, string> = {
  subscription: 'Subscription',
  credits: 'WURK Credits',
  card_check: 'Card check (refunded)',
};
const STATUS_TONE: Record<string, 'success' | 'danger' | 'warning' | 'neutral'> = {
  succeeded: 'success',
  refunded: 'neutral',
  failed: 'danger',
  pending: 'warning',
};

function Details({ account }: Pick<Props, 'account'>) {
  const form = useForm({
    billing_email: account.billing_email,
    billing_name: account.billing_name,
    vat_number: account.vat_number ?? '',
  });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.put('billing/account', { preserveScroll: true });
  };
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Billing details')}</CardTitle>
        <CardDescription>
          {__('Shown on your tax invoices. Receipts go to this email.')}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="grid gap-5 sm:grid-cols-2" noValidate>
          <Field label={__('Billing email')} error={form.errors.billing_email} required>
            <Input
              type="email"
              value={form.data.billing_email}
              onChange={(e) => form.setData('billing_email', e.target.value)}
              autoComplete="email"
            />
          </Field>
          <Field label={__('Name on invoices')} error={form.errors.billing_name} required>
            <Input
              value={form.data.billing_name}
              onChange={(e) => form.setData('billing_name', e.target.value)}
              autoComplete="organization"
            />
          </Field>
          <Field
            label={__('VAT number')}
            hint={__('If your business is VAT-registered')}
            error={form.errors.vat_number}
          >
            <Input
              value={form.data.vat_number}
              onChange={(e) => form.setData('vat_number', e.target.value)}
              inputMode="numeric"
            />
          </Field>
          <div className="flex items-end">
            <Button type="submit" variant="secondary" loading={form.processing}>
              {__('Save details')}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

function ChangePackage({
  subscription,
  packagesOnSale,
}: {
  subscription: NonNullable<Props['subscription']>;
  packagesOnSale: Props['packagesOnSale'];
}) {
  const [choice, setChoice] = useState({
    package_id: subscription.package_id,
    period: subscription.period,
  });
  const [quote, setQuote] = useState<{ kind: string; charge_cents: number } | null>(null);
  const form = useForm({});
  const unchanged =
    choice.package_id === subscription.package_id && choice.period === subscription.period;

  useEffect(() => {
    if (unchanged) return;
    const ctrl = new AbortController();
    fetch(
      `billing/change/quote?package_id=${encodeURIComponent(choice.package_id)}&period=${choice.period}`,
      { headers: { Accept: 'application/json' }, signal: ctrl.signal },
    )
      .then((r) => (r.ok ? r.json() : null))
      .then((q) => setQuote(q))
      .catch(() => undefined);
    return () => ctrl.abort();
  }, [choice, unchanged]);
  const shown = unchanged ? null : quote;

  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Change package')}</CardTitle>
        <CardDescription>
          {__(
            'Upgrades start now and you pay only for the rest of this period. Downgrades start at your next renewal.',
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {subscription.pending && (
          <div className="bg-sunken flex flex-wrap items-center justify-between gap-3 rounded-xl p-4 text-sm">
            <span>
              {subscription.pending.package}, {subscription.pending.period}
              {__(', starts at your next renewal.')}
            </span>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => router.post('billing/change/cancel', {}, { preserveScroll: true })}
            >
              {__('Keep my current package')}
            </Button>
          </div>
        )}
        <div className="grid gap-4 sm:grid-cols-[1.5fr_1fr_auto] sm:items-end">
          <Field label={__('Package')}>
            {(control) => (
              <Select
                value={choice.package_id}
                onValueChange={(v) => setChoice((c) => ({ ...c, package_id: v }))}
              >
                <SelectTrigger {...control}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {packagesOnSale.map((p) => (
                    <SelectItem
                      key={p.id}
                      value={p.id}
                    >{`${p.name} — ${rand(p.monthly_total_cents)}/month`}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </Field>
          <Field label={__('Billing')}>
            {(control) => (
              <Select
                value={choice.period}
                onValueChange={(v) => setChoice((c) => ({ ...c, period: v }))}
              >
                <SelectTrigger {...control}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="monthly">{__('Monthly')}</SelectItem>
                  <SelectItem value="yearly">{__('Yearly')}</SelectItem>
                </SelectContent>
              </Select>
            )}
          </Field>
          <Button
            disabled={unchanged || form.processing}
            loading={form.processing}
            onClick={() => {
              form.transform(() => choice);
              form.post('billing/change', { preserveScroll: true });
            }}
          >
            {shown?.kind === 'upgrade' && shown.charge_cents > 0
              ? `Upgrade and pay ${rand(shown.charge_cents)} + VAT`
              : shown?.kind === 'scheduled'
                ? 'Change at renewal'
                : 'Change package'}
          </Button>
        </div>
        {(form.errors as Record<string, string>).package && (
          <p role="alert" className="text-danger-11 text-sm">
            {(form.errors as Record<string, string>).package}
          </p>
        )}
      </CardContent>
    </Card>
  );
}

function Documents({ invoices, creditNotes }: Pick<Props, 'invoices' | 'creditNotes'>) {
  if (invoices.length === 0) return null;
  return (
    <Table label={__('Tax invoices')}>
      <TableHeader>
        <TableRow>
          <TableHead>{__('Invoice')}</TableHead>
          <TableHead>{__('Date')}</TableHead>
          <TableHead className="text-right">{__('Amount')}</TableHead>
          <TableHead>
            <span className="sr-only">{__('Download')}</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {invoices.map((i) => (
          <TableRow key={i.id}>
            <TableCell className="font-medium">
              {i.number}
              {i.credited_cents > 0 && (
                <Badge className="ml-2">
                  {__('Credited')} {rand(i.credited_cents)}
                </Badge>
              )}
            </TableCell>
            <TableCell className="text-fg-muted">{date(i.issued_at)}</TableCell>
            <TableCell className="text-right tabular-nums">{rand(i.total_cents)}</TableCell>
            <TableCell className="text-right">
              <a
                className="text-accent-fg font-medium underline-offset-4 hover:underline"
                href={`billing/invoices/${i.id}`}
              >
                {__('PDF')}
                <span className="sr-only">
                  {' '}
                  {__('of')} {i.number}
                </span>
              </a>
            </TableCell>
          </TableRow>
        ))}
        {creditNotes.map((n) => (
          <TableRow key={n.id}>
            <TableCell className="font-medium">{n.number}</TableCell>
            <TableCell className="text-fg-muted">{date(n.issued_at)}</TableCell>
            <TableCell className="text-right tabular-nums">−{rand(n.total_cents)}</TableCell>
            <TableCell className="text-right">
              <a
                className="text-accent-fg font-medium underline-offset-4 hover:underline"
                href={`billing/credit-notes/${n.id}`}
              >
                {__('PDF')}
                <span className="sr-only">
                  {' '}
                  {__('of')} {n.number}
                </span>
              </a>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}

function DebitOrder({ debitOrders }: Pick<Props, 'debitOrders'>) {
  const firstBank = Object.keys(debitOrders.banks)[0] ?? '';
  const form = useForm({
    holder_name: '',
    id_number: '',
    bank: firstBank,
    branch_code: debitOrders.banks[firstBank] ?? '',
    account_number: '',
    account_type: 'cheque',
    collection_day: 1,
  });
  const m = debitOrders.mandate;
  // Most owners pay by card: keep the seven-field form closed until asked for (open again after errors).
  const [open, setOpen] = useState(false);
  const showForm = open || Object.keys(form.errors).length > 0;
  const post = (url: string, data: Record<string, unknown> = {}) =>
    router.post(url, data as never, { preserveScroll: true });

  if (!debitOrders.available) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{__('Debit order')}</CardTitle>
          <CardDescription>
            {__('DebiCheck debit orders open soon. Pay by card until then.')}
          </CardDescription>
        </CardHeader>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Debit order')}</CardTitle>
        <CardDescription>
          {__('Pay each renewal by DebiCheck debit order. You approve it once with your bank.')}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-5">
        {m && (
          <div role="status" className="bg-sunken rounded-xl p-4 text-sm">
            {m.status === 'active' && (
              <p>
                <strong className="font-semibold">{__('Active:')}</strong> {m.bank} {m.account}
                {__(', collected around day')} {m.day} {__('of the month')}
                {debitOrders.method === 'debit_order'
                  ? '.'
                  : ' (renewals currently go to your card).'}
              </p>
            )}
            {m.status === 'pending_authentication' && (
              <p>
                {__('Waiting for you to approve the debit order with')} {m.bank}{' '}
                {__('— check your banking app or SMS.')}
              </p>
            )}
            {m.status === 'rejected' && (
              <p>
                {__('The bank didn’t approve the debit order')}
                {m.failure ? `: ${m.failure}` : ''}
                {__('. You can try again below.')}
              </p>
            )}
          </div>
        )}
        {debitOrders.method === 'debit_order' && (
          <Button
            variant="secondary"
            className="w-fit"
            onClick={() => post('billing/debit-order/use-card')}
          >
            {__('Charge renewals to my card instead')}
          </Button>
        )}
        {(!m || m.status === 'rejected') && !showForm && (
          <Button variant="secondary" className="w-fit" onClick={() => setOpen(true)}>
            {__('Set up a debit order')}
          </Button>
        )}
        {(!m || m.status === 'rejected') && showForm && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              form.post('billing/debit-order', { preserveScroll: true });
            }}
            className="grid gap-5 sm:grid-cols-2"
            noValidate
          >
            <Field label={__('Account holder')} error={form.errors.holder_name} required>
              <Input
                value={form.data.holder_name}
                onChange={(e) => form.setData('holder_name', e.target.value)}
                autoComplete="name"
              />
            </Field>
            <Field label={__('Account holder’s ID number')} error={form.errors.id_number} required>
              <Input
                value={form.data.id_number}
                onChange={(e) => form.setData('id_number', e.target.value)}
                inputMode="numeric"
                autoComplete="off"
              />
            </Field>
            <Field label={__('Bank')} error={form.errors.bank}>
              {(control) => (
                <Select
                  value={form.data.bank}
                  onValueChange={(v) =>
                    form.setData((d) => ({
                      ...d,
                      bank: v,
                      branch_code: debitOrders.banks[v] ?? d.branch_code,
                    }))
                  }
                >
                  <SelectTrigger {...control}>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {Object.keys(debitOrders.banks).map((b) => (
                      <SelectItem key={b} value={b}>
                        {b}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </Field>
            <Field
              label={__('Branch code')}
              hint={__('Universal code filled in for you')}
              error={form.errors.branch_code}
              required
            >
              <Input
                value={form.data.branch_code}
                onChange={(e) => form.setData('branch_code', e.target.value)}
                inputMode="numeric"
              />
            </Field>
            <Field label={__('Account number')} error={form.errors.account_number} required>
              <Input
                value={form.data.account_number}
                onChange={(e) => form.setData('account_number', e.target.value)}
                inputMode="numeric"
                autoComplete="off"
              />
            </Field>
            <Field label={__('Account type')} error={form.errors.account_type}>
              {(control) => (
                <Select
                  value={form.data.account_type}
                  onValueChange={(v) => form.setData('account_type', v)}
                >
                  <SelectTrigger {...control}>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {debitOrders.accountTypes.map((t) => (
                      <SelectItem key={t} value={t}>
                        {t[0]?.toUpperCase() + t.slice(1)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </Field>
            <Field
              label={__('Collection day of the month')}
              hint={__('1 to 28')}
              error={form.errors.collection_day}
            >
              <Input
                type="number"
                min={1}
                max={28}
                value={form.data.collection_day}
                onChange={(e) => form.setData('collection_day', Number(e.target.value))}
              />
            </Field>
            <div className="flex items-end">
              <Button type="submit" loading={form.processing}>
                {__('Set up debit order')}
              </Button>
            </div>
          </form>
        )}
        {debitOrders.simulated && m && (
          <div className="border-border grid gap-2 rounded-xl border border-dashed p-4">
            <p className="text-sm font-medium">{__('Development only — act as the bank')}</p>
            <div className="flex flex-wrap gap-2">
              {[
                'mandate.approved',
                'mandate.rejected',
                'collection.succeeded',
                'collection.failed',
              ].map((t) => (
                <Button
                  key={t}
                  size="sm"
                  variant="ghost"
                  onClick={() => post('billing/debit-order/simulate', { type: t })}
                >
                  {t}
                </Button>
              ))}
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

export default function Billing({
  subscription,
  needsPayment,
  vatPercent,
  account,
  card,
  packs,
  credits,
  creditPacksAllowed,
  payments,
  gatewayReady,
  payMethods,
  wurkEft,
  reference,
  debitOrders,
  stage,
  prepaid,
  minTopUp,
  packagesOnSale,
  invoices,
  creditNotes,
}: Props) {
  const [payWith, setPayWith] = useState<string>(
    gatewayReady ? '' : (payMethods[0]?.provider ?? ''),
  );
  const canPay = (gatewayReady || payMethods.length > 0) && account.saved;
  const [topUp, setTopUp] = useState('');
  const post = (url: string, data: Record<string, unknown> = {}) =>
    router.post(url, { ...data, method: payWith || null } as never, { preserveScroll: true });

  return (
    <AppShell title={__('Billing')}>
      <Head title={__('Billing')} />
      <div className="grid max-w-4xl gap-8">
        <PageHeader
          title={__('Billing')}
          description={`Your package, payments and WURK Credits. Prices include ${vatPercent}% VAT.`}
        />

        {needsPayment && (
          <div role="alert" className="border-warning-6 bg-warning-3 rounded-2xl border p-5">
            <p className="font-semibold">
              {stage === 'suspended'
                ? 'Your business is suspended because the subscription hasn’t been paid. Pay now and everything comes back at once — nothing has been deleted.'
                : stage === 'restricted'
                  ? 'Your last payment didn’t go through, so only Billing is open. Pay now to restore everything.'
                  : subscription?.status === 'trial'
                    ? 'Your free trial has ended.'
                    : 'Your business is ready — pay for your first period to start using it.'}
            </p>
            <p className="text-fg-muted mt-1 text-sm">
              {account.saved
                ? 'Pay by card below. Your website and back office open as soon as the payment is confirmed.'
                : 'Add your billing details below, then pay by card.'}
            </p>
          </div>
        )}
        {!gatewayReady && payMethods.length === 0 && (
          <p role="status" className="bg-sunken text-fg-muted rounded-2xl p-4 text-sm">
            {__('Card payments are being set up and open shortly.')}
          </p>
        )}
        {(gatewayReady ? 1 : 0) + payMethods.length > 1 && (
          <fieldset className="border-border grid gap-2 rounded-2xl border p-4 text-sm">
            <legend className="px-1 font-semibold">{__('Pay with')}</legend>
            {gatewayReady && (
              <label className="flex items-center gap-2">
                <input
                  type="radio"
                  name="pay-with"
                  checked={payWith === ''}
                  onChange={() => setPayWith('')}
                />
                {__('Card (you can save it for automatic payments)')}
              </label>
            )}
            {payMethods.map((m) => (
              <label key={m.provider} className="flex items-center gap-2">
                <input
                  type="radio"
                  name="pay-with"
                  checked={payWith === m.provider}
                  onChange={() => setPayWith(m.provider)}
                />
                {m.name}
              </label>
            ))}
          </fieldset>
        )}
        {wurkEft && (
          <div className="border-border rounded-2xl border p-4 text-sm">
            <p className="font-semibold">{__('Or pay WURK by EFT')}</p>
            <p className="mt-1 whitespace-pre-line">{wurkEft}</p>
            <p className="mt-1">
              {__('Use')} <strong>{reference}</strong>{' '}
              {__('as the reference. We’ll confirm it once it reaches our account.')}
            </p>
          </div>
        )}

        {subscription && (
          <Card>
            <CardHeader>
              <div className="flex items-start justify-between gap-4">
                <div>
                  <CardTitle>{subscription.package}</CardTitle>
                  <CardDescription>
                    {rand(subscription.period_total_cents)}{' '}
                    {subscription.period === 'yearly' ? 'a year' : 'a month'} {__('incl. VAT')}
                  </CardDescription>
                </div>
                <Badge
                  tone={
                    subscription.status === 'active'
                      ? 'success'
                      : subscription.status === 'trial'
                        ? 'brand'
                        : 'warning'
                  }
                  dot
                >
                  {subscription.status === 'pending_payment'
                    ? 'Waiting for payment'
                    : subscription.status === 'past_due'
                      ? 'Payment overdue'
                      : subscription.status === 'trial'
                        ? 'Free trial'
                        : 'Active'}
                </Badge>
              </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
              <p className="text-fg-muted text-sm">
                {subscription.status === 'trial' &&
                  `Trial ends ${date(subscription.trial_ends_at)}.`}
                {subscription.status === 'active' &&
                  `Renews ${date(subscription.current_period_end)}${card ? ` on your ${card.brand ?? 'card'} ending ${card.last4}` : ''}.`}
                {(subscription.status === 'pending_payment' ||
                  subscription.status === 'past_due') &&
                  'Not paid yet.'}
              </p>
              {(needsPayment ||
                subscription.status === 'trial' ||
                subscription.status === 'past_due') && (
                <Button disabled={!canPay} onClick={() => post('billing/pay')}>
                  {subscription.status === 'trial' && !needsPayment
                    ? 'Pay now to continue after the trial'
                    : `Pay ${rand(subscription.period_total_cents)}`}
                </Button>
              )}
            </CardContent>
          </Card>
        )}

        {subscription && packagesOnSale.length > 0 && (
          <ChangePackage subscription={subscription} packagesOnSale={packagesOnSale} />
        )}

        <Details account={account} />

        <Card>
          <CardHeader>
            <CardTitle>{__('Card')}</CardTitle>
            <CardDescription>
              {__('We keep a secure token from Paystack, never your card number.')}
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <p className="flex items-center gap-2.5">
              <CreditCard className="text-fg-muted size-5" aria-hidden />
              {card
                ? `${card.brand ?? 'Card'} ending ${card.last4}, expires ${card.expires}`
                : 'No card saved yet.'}
            </p>
            <Button variant="secondary" disabled={!canPay} onClick={() => post('billing/card')}>
              {card ? 'Use a different card' : 'Add a card'}
            </Button>
          </CardContent>
          <CardContent className="text-fg-muted pt-0 text-sm">
            {__('Adding a card makes a R1 check that is refunded straight away.')}
          </CardContent>
        </Card>

        <DebitOrder debitOrders={debitOrders} />

        {!creditPacksAllowed && (
          <Card>
            <CardHeader>
              <CardTitle>{__('Need more WURK Credits?')}</CardTitle>
              <CardDescription>
                {__(
                  'Your package doesn’t include buying credit packs. Upgrade for more monthly credits.',
                )}
              </CardDescription>
            </CardHeader>
          </Card>
        )}

        {prepaid && (
          <Card>
            <CardHeader>
              <CardTitle>{__('Pay as you go')}</CardTitle>
              <CardDescription>
                {__('Your plan is paid day by day from your WURK credits:')}{' '}
                {(prepaid.daily_milli / 1000).toLocaleString(undefined, {
                  maximumFractionDigits: 1,
                })}{' '}
                {__('credits a day.')}
              </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
              {prepaid.ran_out_on ? (
                <p role="alert" className="text-danger">
                  {__('Your credits ran out on')} {prepaid.ran_out_on}
                  {__('. Everything keeps working for')} {prepaid.grace_days}{' '}
                  {__('days from then. Top up to carry on.')}
                </p>
              ) : (
                <p>
                  {__('Your plan is paid until today, with credits for about')}{' '}
                  <strong>
                    {prepaid.days_left} {__('more')} {prepaid.days_left === 1 ? 'day' : 'days'}
                  </strong>
                  .
                </p>
              )}
              <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  post('billing/credits', { amount: topUp });
                }}
              >
                <Field label={`Top up any amount (from R${minTopUp})`}>
                  <Input
                    inputMode="decimal"
                    value={topUp}
                    onChange={(e) => setTopUp(e.target.value)}
                    placeholder={String(minTopUp)}
                  />
                </Field>
                <Button type="submit" disabled={!canPay || Number(topUp) < minTopUp}>
                  {__('Top up')}
                </Button>
              </form>
            </CardContent>
          </Card>
        )}

        <CreditSettings credits={credits} packs={packs} />

        {packs.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>{__('Buy WURK Credits')}</CardTitle>
              <CardDescription>
                {__('Bought credits never expire. Your monthly allowance is used first.')}
              </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 sm:grid-cols-3">
              {packs.map((p) => (
                <div key={p.id} className="border-border flex flex-col gap-3 rounded-xl border p-4">
                  <p>
                    <span className="block font-semibold">
                      {p.credits.toLocaleString()} {__('credits')}
                    </span>
                    <span className="text-fg-muted text-sm">
                      {rand(p.total_cents)} {__('incl. VAT')}
                    </span>
                  </p>
                  <Button
                    size="sm"
                    disabled={!canPay}
                    onClick={() =>
                      post('billing/credits', { pack_id: p.id, saved_card: card !== null })
                    }
                  >
                    {card ? `Buy with card ending ${card.last4}` : 'Buy'}
                  </Button>
                </div>
              ))}
            </CardContent>
          </Card>
        )}

        <Documents invoices={invoices} creditNotes={creditNotes} />

        {payments.length === 0 ? (
          <EmptyState
            icon={Receipt}
            title={__('No payments yet')}
            description={__('Your payments and receipts will appear here.')}
          />
        ) : (
          <Table label={__('Payments')}>
            <TableHeader>
              <TableRow>
                <TableHead>{__('Date')}</TableHead>
                <TableHead>{__('For')}</TableHead>
                <TableHead className="text-right">{__('Amount')}</TableHead>
                <TableHead>{__('Status')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {payments.map((p) => (
                <TableRow key={p.id}>
                  <TableCell className="text-fg-muted">{date(p.at)}</TableCell>
                  <TableCell>{PURPOSE[p.purpose] ?? p.purpose}</TableCell>
                  <TableCell className="text-right tabular-nums">{rand(p.total_cents)}</TableCell>
                  <TableCell>
                    <Badge tone={STATUS_TONE[p.status] ?? 'neutral'} title={p.failure ?? undefined}>
                      {p.status === 'succeeded'
                        ? 'Paid'
                        : p.status === 'failed'
                          ? 'Failed'
                          : p.status === 'refunded'
                            ? 'Refunded'
                            : 'Waiting'}
                    </Badge>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </div>
    </AppShell>
  );
}

/** Auto top-up and the monthly usage summary (Sprint 15.5b). */
function CreditSettings({ credits, packs }: { credits: Props['credits']; packs: Props['packs'] }) {
  const form = useForm({
    auto_top_up: credits.auto_top_up,
    pack_id: credits.pack_id ?? '',
    cap: credits.cap_cents === null ? '' : (credits.cap_cents / 100).toFixed(2),
    usage_summary: credits.usage_summary,
  });
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Credits')}</CardTitle>
      </CardHeader>
      <CardContent>
        <form
          aria-label={__('Credit settings')}
          className="grid gap-3 text-sm"
          onSubmit={(e) => {
            e.preventDefault();
            form.put(`${window.location.pathname.replace(/\/$/, '')}/credit-settings`, {
              preserveScroll: true,
            });
          }}
        >
          {packs.length > 0 && (
            <>
              <label className="flex items-center gap-2">
                <Checkbox
                  checked={form.data.auto_top_up}
                  onCheckedChange={(on) => form.setData('auto_top_up', on === true)}
                />
                {__('Top up automatically with my saved card when credits run low')}{' '}
                <HelpTip>
                  {__(
                    'When your credits drop below your low-balance warning, we buy the pack you choose with your saved card — never more than your monthly limit — and email you each time.',
                  )}
                </HelpTip>
              </label>
              {form.data.auto_top_up && (
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label={__('Pack to buy')} error={form.errors.pack_id}>
                    <select
                      className="border-border bg-bg h-10 w-full rounded-md border px-3"
                      value={form.data.pack_id}
                      onChange={(e) => form.setData('pack_id', e.target.value)}
                    >
                      <option value="">{__('Choose a pack')}</option>
                      {packs.map((p) => (
                        <option key={p.id} value={p.id}>
                          {p.name} — {p.credits} {__('credits')}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field
                    label={__('Most to spend a month, excl. VAT (R, optional)')}
                    error={form.errors.cap}
                  >
                    <Input
                      inputMode="decimal"
                      value={form.data.cap}
                      onChange={(e) => form.setData('cap', e.target.value)}
                    />
                  </Field>
                  <p className="text-fg-muted sm:col-span-2">
                    {__('We email you every time we top up. Topped up this month:')}{' '}
                    {rand(credits.spent_cents)} {__('excl. VAT.')}
                  </p>
                </div>
              )}
            </>
          )}
          <label className="flex items-center gap-2">
            <Checkbox
              checked={form.data.usage_summary}
              onCheckedChange={(on) => form.setData('usage_summary', on === true)}
            />
            {__('Email me a monthly summary of the credits we used')}
          </label>
          <Button type="submit" className="justify-self-start" loading={form.processing}>
            {__('Save')}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
