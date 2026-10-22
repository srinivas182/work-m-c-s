import { Head, router, useForm } from '@inertiajs/react';
import { CreditCard, Receipt } from 'lucide-react';
import type { FormEvent } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
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
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface Props {
  subscription: {
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
  payments: {
    id: string;
    at: string;
    purpose: string;
    total_cents: number;
    status: string;
    failure: string | null;
  }[];
  gatewayReady: boolean;
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
        <CardTitle>Billing details</CardTitle>
        <CardDescription>Shown on your tax invoices. Receipts go to this email.</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="grid gap-5 sm:grid-cols-2" noValidate>
          <Field label="Billing email" error={form.errors.billing_email} required>
            <Input
              type="email"
              value={form.data.billing_email}
              onChange={(e) => form.setData('billing_email', e.target.value)}
              autoComplete="email"
            />
          </Field>
          <Field label="Name on invoices" error={form.errors.billing_name} required>
            <Input
              value={form.data.billing_name}
              onChange={(e) => form.setData('billing_name', e.target.value)}
              autoComplete="organization"
            />
          </Field>
          <Field
            label="VAT number"
            hint="If your business is VAT-registered"
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
              Save details
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
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
  const post = (url: string, data: Record<string, unknown> = {}) =>
    router.post(url, data as never, { preserveScroll: true });

  if (!debitOrders.available) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Debit order</CardTitle>
          <CardDescription>
            DebiCheck debit orders open soon. Pay by card until then.
          </CardDescription>
        </CardHeader>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Debit order</CardTitle>
        <CardDescription>
          Pay each renewal by DebiCheck debit order. You approve it once with your bank.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-5">
        {m && (
          <div role="status" className="bg-sunken rounded-xl p-4 text-sm">
            {m.status === 'active' && (
              <p>
                <strong className="font-semibold">Active:</strong> {m.bank} {m.account}, collected
                around day {m.day} of the month
                {debitOrders.method === 'debit_order'
                  ? '.'
                  : ' (renewals currently go to your card).'}
              </p>
            )}
            {m.status === 'pending_authentication' && (
              <p>
                Waiting for you to approve the debit order with {m.bank} — check your banking app or
                SMS.
              </p>
            )}
            {m.status === 'rejected' && (
              <p>
                The bank didn’t approve the debit order{m.failure ? `: ${m.failure}` : ''}. You can
                try again below.
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
            Charge renewals to my card instead
          </Button>
        )}
        {(!m || m.status === 'rejected') && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              form.post('billing/debit-order', { preserveScroll: true });
            }}
            className="grid gap-5 sm:grid-cols-2"
            noValidate
          >
            <Field label="Account holder" error={form.errors.holder_name} required>
              <Input
                value={form.data.holder_name}
                onChange={(e) => form.setData('holder_name', e.target.value)}
                autoComplete="name"
              />
            </Field>
            <Field label="Account holder’s ID number" error={form.errors.id_number} required>
              <Input
                value={form.data.id_number}
                onChange={(e) => form.setData('id_number', e.target.value)}
                inputMode="numeric"
                autoComplete="off"
              />
            </Field>
            <Field label="Bank" error={form.errors.bank}>
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
              label="Branch code"
              hint="Universal code filled in for you"
              error={form.errors.branch_code}
              required
            >
              <Input
                value={form.data.branch_code}
                onChange={(e) => form.setData('branch_code', e.target.value)}
                inputMode="numeric"
              />
            </Field>
            <Field label="Account number" error={form.errors.account_number} required>
              <Input
                value={form.data.account_number}
                onChange={(e) => form.setData('account_number', e.target.value)}
                inputMode="numeric"
                autoComplete="off"
              />
            </Field>
            <Field label="Account type" error={form.errors.account_type}>
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
              label="Collection day of the month"
              hint="1 to 28"
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
                Set up debit order
              </Button>
            </div>
          </form>
        )}
        {debitOrders.simulated && m && (
          <div className="border-border grid gap-2 rounded-xl border border-dashed p-4">
            <p className="text-sm font-medium">Development only — act as the bank</p>
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
  payments,
  gatewayReady,
  debitOrders,
}: Props) {
  const canPay = gatewayReady && account.saved;
  const post = (url: string, data: Record<string, unknown> = {}) =>
    router.post(url, data as never, { preserveScroll: true });

  return (
    <AppShell title="Billing">
      <Head title="Billing" />
      <div className="grid max-w-4xl gap-8">
        <PageHeader
          title="Billing"
          description={`Your package, payments and WURK Credits. Prices include ${vatPercent}% VAT.`}
        />

        {needsPayment && (
          <div role="alert" className="border-warning-6 bg-warning-3 rounded-2xl border p-5">
            <p className="font-semibold">
              {subscription?.status === 'trial'
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
        {!gatewayReady && (
          <p role="status" className="bg-sunken text-fg-muted rounded-2xl p-4 text-sm">
            Card payments are being set up and open shortly.
          </p>
        )}

        {subscription && (
          <Card>
            <CardHeader>
              <div className="flex items-start justify-between gap-4">
                <div>
                  <CardTitle>{subscription.package}</CardTitle>
                  <CardDescription>
                    {rand(subscription.period_total_cents)}{' '}
                    {subscription.period === 'yearly' ? 'a year' : 'a month'} incl. VAT
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

        <Details account={account} />

        <Card>
          <CardHeader>
            <CardTitle>Card</CardTitle>
            <CardDescription>
              We keep a secure token from Paystack, never your card number.
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
            Adding a card makes a R1 check that is refunded straight away.
          </CardContent>
        </Card>

        <DebitOrder debitOrders={debitOrders} />

        {packs.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Buy WURK Credits</CardTitle>
              <CardDescription>
                Bought credits never expire. Your monthly allowance is used first.
              </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 sm:grid-cols-3">
              {packs.map((p) => (
                <div key={p.id} className="border-border flex flex-col gap-3 rounded-xl border p-4">
                  <p>
                    <span className="block font-semibold">
                      {p.credits.toLocaleString()} credits
                    </span>
                    <span className="text-fg-muted text-sm">{rand(p.total_cents)} incl. VAT</span>
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

        {payments.length === 0 ? (
          <EmptyState
            icon={Receipt}
            title="No payments yet"
            description="Your payments and receipts will appear here."
          />
        ) : (
          <Table label="Payments">
            <TableHeader>
              <TableRow>
                <TableHead>Date</TableHead>
                <TableHead>For</TableHead>
                <TableHead className="text-right">Amount</TableHead>
                <TableHead>Status</TableHead>
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
