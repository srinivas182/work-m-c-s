import { router } from '@inertiajs/react';
import { Badge, EmptyState } from '@/design-system';
import { Coins } from 'lucide-react';
import { rand } from '@/lib/money';
import { AgentLayout } from '../../components/AgentLayout';

interface Line {
  at: string;
  business: string;
  level: number;
  kind: 'earned' | 'reversed';
  cents: number;
  payable: string;
  pending: boolean;
}

/** Every commission line for a period — downloadable for the agent's own records. */
export default function AgentEarnings({
  period,
  periods,
  lines,
}: {
  period: string;
  periods: Record<string, string>;
  lines: Line[];
}) {
  const total = lines.reduce((a, l) => a + l.cents, 0);
  return (
    <AgentLayout title="Earnings">
      <div className="flex flex-wrap items-end gap-3 text-sm">
        <label className="flex items-center gap-2">
          Period
          <select
            className="border-border bg-bg h-10 rounded-md border px-3"
            value={period}
            onChange={(e) =>
              router.get('/agent/earnings', { period: e.target.value }, { preserveScroll: true })
            }
          >
            {Object.entries(periods).map(([k, label]) => (
              <option key={k} value={k}>
                {label}
              </option>
            ))}
          </select>
        </label>
        <a className="underline" href={`/agent/earnings?period=${period}&format=csv`}>
          Download CSV
        </a>
        <span>Total: {rand(total)}</span>
      </div>
      {lines.length === 0 ? (
        <EmptyState
          icon={Coins}
          title="No earnings in this period"
          description="Commission appears here when businesses you brought pay their WURK subscription."
        />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">Earnings</caption>
            <thead>
              <tr>
                <th scope="col">Date</th>
                <th scope="col">Business</th>
                <th scope="col">Level</th>
                <th scope="col">Amount</th>
                <th scope="col">Payable</th>
              </tr>
            </thead>
            <tbody>
              {lines.map((l, i) => (
                <tr key={i}>
                  <td>{l.at}</td>
                  <td>{l.business}</td>
                  <td>{l.level === 0 ? 'Own' : `Level ${l.level}`}</td>
                  <td>
                    {rand(l.cents)}
                    {l.kind === 'reversed' && <span className="text-fg-muted"> (refund)</span>}
                  </td>
                  <td>{l.pending ? <Badge tone="neutral">From {l.payable}</Badge> : 'Payable'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </AgentLayout>
  );
}
