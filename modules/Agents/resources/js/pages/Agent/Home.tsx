import { Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system';
import { rand } from '@/lib/money';
import { AgentLayout } from '../../components/AgentLayout';

interface Props {
  code: string;
  link: string;
  status: 'active' | 'suspended';
  hasBank: boolean;
  businesses: {
    total: number;
    this_month: number;
    active: number;
    at_risk: number;
    churned: number;
  };
  commission: { pending: number; available: number; paid_out: number };
  this_month_cents: number;
  next_payout: {
    state: 'included' | 'held' | 'skipped' | 'nothing';
    reason: string | null;
    minimum_cents: number;
  };
  upcoming: { date: string; cents: number }[];
  months: { month: string; cents: number }[];
}

const monthName = (m: string) =>
  new Date(`${m}-01T00:00:00`).toLocaleDateString('en-ZA', { month: 'short', year: '2-digit' });

/** The agent's dashboard (Sprint 14.6a). */
export default function AgentHome(p: Props) {
  const max = Math.max(1, ...p.months.map((m) => m.cents));
  return (
    <AgentLayout title="Your agent area">
      {p.status === 'suspended' && (
        <p role="alert" className="text-danger text-sm">
          Your agent account is suspended. Contact WURK.
        </p>
      )}
      <ul aria-label="At a glance" className="grid gap-3 sm:grid-cols-2">
        <Tile
          label="Businesses"
          value={String(p.businesses.total)}
          hint={`${p.businesses.this_month} this month`}
        />
        <Tile
          label="Active · at risk · churned"
          value={`${p.businesses.active} · ${p.businesses.at_risk} · ${p.businesses.churned}`}
          hint={
            p.businesses.at_risk > 0
              ? 'At-risk businesses are behind on payment — see My businesses'
              : undefined
          }
        />
        <Tile
          label="Available to be paid"
          value={rand(p.commission.available)}
          hint={`${rand(p.commission.pending)} pending · ${rand(p.commission.paid_out)} paid out`}
        />
        <Tile label="Earned this month" value={rand(p.this_month_cents)} />
      </ul>

      <Card>
        <CardHeader>
          <CardTitle>Next payout</CardTitle>
        </CardHeader>
        <CardContent className="text-sm">
          {p.next_payout.state === 'included' && <p>You’ll be in WURK’s next payout batch.</p>}
          {p.next_payout.state === 'held' && (
            <p>{p.next_payout.reason} — WURK will check with you before paying.</p>
          )}
          {p.next_payout.state === 'skipped' && (
            <p>
              Not in the next batch: {p.next_payout.reason}
              {p.next_payout.reason === 'Below the minimum payout'
                ? ` (${rand(p.next_payout.minimum_cents)})`
                : ''}
              .
              {p.next_payout.reason === 'No banking details' && (
                <>
                  {' '}
                  <Link className="underline" href="/agent/bank">
                    Add them
                  </Link>
                </>
              )}
            </p>
          )}
          {p.next_payout.state === 'nothing' && <p>Nothing to pay yet.</p>}
        </CardContent>
      </Card>

      {p.upcoming.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Becoming available (next 30 days)</CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="grid gap-1 text-sm">
              {p.upcoming.map((u) => (
                <li key={u.date}>
                  {u.date}: {rand(u.cents)}
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Earnings by month</CardTitle>
        </CardHeader>
        <CardContent>
          <div aria-hidden="true" className="flex h-32 items-end gap-1">
            {p.months.map((m) => (
              <div
                key={m.month}
                className="bg-primary flex-1 rounded-t"
                style={{ height: `${Math.max(2, (Math.max(0, m.cents) / max) * 100)}%` }}
                title={`${monthName(m.month)}: ${rand(m.cents)}`}
              />
            ))}
          </div>
          <table className="sr-only">
            <caption>Earnings by month</caption>
            <tbody>
              {p.months.map((m) => (
                <tr key={m.month}>
                  <th scope="row">{monthName(m.month)}</th>
                  <td>{rand(m.cents)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="grid gap-2 pt-6 text-sm">
          <p>
            Your link: <span className="font-mono break-all">{p.link}</span> (code{' '}
            <span className="font-mono">{p.code}</span>)
          </p>
          {!p.hasBank && (
            <p>
              <Link className="underline" href="/agent/bank">
                Add your banking details
              </Link>{' '}
              so WURK can pay you.
            </p>
          )}
        </CardContent>
      </Card>
    </AgentLayout>
  );
}

function Tile({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <li className="border-border grid gap-1 rounded-lg border p-4">
      <span className="text-fg-muted text-sm">{label}</span>
      <span className="text-2xl font-semibold">{value}</span>
      {hint && <span className="text-fg-muted text-sm">{hint}</span>}
    </li>
  );
}
