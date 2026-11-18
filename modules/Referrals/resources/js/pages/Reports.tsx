import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';
import { __ } from '@/i18n';

interface Props {
  period: string;
  periods: Record<string, string>;
  cost: {
    sales_cents: number;
    cashback_cents: number;
    referral_cents: number;
    months: {
      month: string;
      sales_cents: number;
      cashback_cents: number;
      referral_cents: number;
    }[];
  };
  top: { name: string; earned_cents: number; brought: number }[];
  liability: {
    wallets_cents: number;
    pending_cents: number;
    payouts_waiting_cents: number;
    expiring_30_days_cents: number;
  };
  payouts: { paid: number; paid_cents: number; declined: number };
}

const share = (part: number, of: number) => (of > 0 ? `${((part / of) * 100).toFixed(1)}%` : '—');

/** Customers → Referral reports. */
export default function Reports({ period, periods, cost, top, liability, payouts }: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  const total = cost.cashback_cents + cost.referral_cents;
  return (
    <AppShell title={__('Referral reports')}>
      <Head title={__('Referral reports')} />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title={__('Referral reports')}
          description={__(
            'What your referral programme brings in, what it costs, and what you owe in wallets.',
          )}
        />
        <label className="flex items-center gap-2 text-sm">
          {__('Period')}
          <select
            className="border-border bg-bg h-10 rounded-md border px-3"
            value={period}
            onChange={(e) => router.get(base, { period: e.target.value }, { preserveScroll: true })}
          >
            {Object.entries(periods).map(([k, label]) => (
              <option key={k} value={k}>
                {label}
              </option>
            ))}
          </select>
        </label>

        <Card>
          <CardHeader>
            <CardTitle>{__('Referral sales and what they cost')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3 text-sm">
            <p>
              {__('Sales to referred customers:')} <strong>{rand(cost.sales_cents)}</strong>{' '}
              {__('· Rewards:')} <strong>{rand(total)}</strong> ({share(total, cost.sales_cents)}{' '}
              {__('of those sales) —')} {rand(cost.cashback_cents)} {__('back on own purchases,')}{' '}
              {rand(cost.referral_cents)} {__('for referrals.')}
            </p>
            {cost.months.length > 0 && (
              <div className="overflow-x-auto">
                <table className="w-full text-left">
                  <caption className="sr-only">{__('By month')}</caption>
                  <thead>
                    <tr>
                      <th scope="col">{__('Month')}</th>
                      <th scope="col">{__('Referral sales')}</th>
                      <th scope="col">{__('Own-purchase rewards')}</th>
                      <th scope="col">{__('Referral rewards')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {cost.months.map((m) => (
                      <tr key={m.month}>
                        <th scope="row">{m.month}</th>
                        <td>{rand(m.sales_cents)}</td>
                        <td>{rand(m.cashback_cents)}</td>
                        <td>{rand(m.referral_cents)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Top referrers')}</CardTitle>
          </CardHeader>
          <CardContent className="text-sm">
            {top.length === 0 ? (
              <p className="text-fg-muted">{__('No referral rewards in this period.')}</p>
            ) : (
              <ol aria-label={__('Top referrers')} className="grid list-decimal gap-1 pl-5">
                {top.map((t, i) => (
                  <li key={i}>
                    {t.name} — {rand(t.earned_cents)} {__('earned ·')}{' '}
                    {t.brought === 1 ? '1 person brought' : `${t.brought} people brought`}
                  </li>
                ))}
              </ol>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('What you owe in wallets (now)')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-1 text-sm">
            <p>
              {__('Available in customers’ wallets:')}{' '}
              <strong>{rand(liability.wallets_cents)}</strong>
            </p>
            <p>
              {__('On its way (still in the return window):')} {rand(liability.pending_cents)}
            </p>
            <p>
              {__('Payouts waiting to be paid:')} {rand(liability.payouts_waiting_cents)}
            </p>
            <p className="text-fg-muted">
              {__('Expiring in the next 30 days if unused:')}{' '}
              {rand(liability.expiring_30_days_cents)}
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Payouts in this period')}</CardTitle>
          </CardHeader>
          <CardContent className="text-sm">
            {payouts.paid} {__('paid (')}
            {rand(payouts.paid_cents)}) · {payouts.declined} {__('declined')}
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
