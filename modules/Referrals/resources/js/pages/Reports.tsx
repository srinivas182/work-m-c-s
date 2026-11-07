import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';

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
    <AppShell title="Referral reports">
      <Head title="Referral reports" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Referral reports"
          description="What your referral programme brings in, what it costs, and what you owe in wallets."
        />
        <label className="flex items-center gap-2 text-sm">
          Period
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
            <CardTitle>Referral sales and what they cost</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3 text-sm">
            <p>
              Sales to referred customers: <strong>{rand(cost.sales_cents)}</strong> · Rewards:{' '}
              <strong>{rand(total)}</strong> ({share(total, cost.sales_cents)} of those sales) —{' '}
              {rand(cost.cashback_cents)} back on own purchases, {rand(cost.referral_cents)} for
              referrals.
            </p>
            {cost.months.length > 0 && (
              <div className="overflow-x-auto">
                <table className="w-full text-left">
                  <caption className="sr-only">By month</caption>
                  <thead>
                    <tr>
                      <th scope="col">Month</th>
                      <th scope="col">Referral sales</th>
                      <th scope="col">Own-purchase rewards</th>
                      <th scope="col">Referral rewards</th>
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
            <CardTitle>Top referrers</CardTitle>
          </CardHeader>
          <CardContent className="text-sm">
            {top.length === 0 ? (
              <p className="text-fg-muted">No referral rewards in this period.</p>
            ) : (
              <ol aria-label="Top referrers" className="grid list-decimal gap-1 pl-5">
                {top.map((t, i) => (
                  <li key={i}>
                    {t.name} — {rand(t.earned_cents)} earned ·{' '}
                    {t.brought === 1 ? '1 person brought' : `${t.brought} people brought`}
                  </li>
                ))}
              </ol>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>What you owe in wallets (now)</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-1 text-sm">
            <p>
              Available in customers’ wallets: <strong>{rand(liability.wallets_cents)}</strong>
            </p>
            <p>On its way (still in the return window): {rand(liability.pending_cents)}</p>
            <p>Payouts waiting to be paid: {rand(liability.payouts_waiting_cents)}</p>
            <p className="text-fg-muted">
              Expiring in the next 30 days if unused: {rand(liability.expiring_30_days_cents)}
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Payouts in this period</CardTitle>
          </CardHeader>
          <CardContent className="text-sm">
            {payouts.paid} paid ({rand(payouts.paid_cents)}) · {payouts.declined} declined
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
