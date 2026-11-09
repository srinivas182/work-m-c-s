import { Badge, EmptyState } from '@/design-system';
import { Wallet } from 'lucide-react';
import { rand } from '@/lib/money';
import { AgentLayout } from '../../components/AgentLayout';

interface Payout {
  reference: string;
  date: string;
  cents: number;
  bank: string;
  status: 'being_paid' | 'paid' | 'bounced';
}

const STATUS = {
  being_paid: ['Being paid', 'warning'],
  paid: ['Paid', 'success'],
  bounced: ['Bounced — back in your balance', 'danger'],
} as const;

/** What WURK has paid the agent. */
export default function AgentPayouts({ payouts }: { payouts: Payout[] }) {
  return (
    <AgentLayout title="Payouts">
      {payouts.length === 0 ? (
        <EmptyState
          icon={Wallet}
          title="No payouts yet"
          description="When WURK pays your commission, it appears here with the bank reference."
        />
      ) : (
        <ul aria-label="Payouts" className="grid gap-2 text-sm">
          {payouts.map((p) => (
            <li
              key={p.reference + p.status}
              className="border-border flex flex-wrap items-center gap-2 rounded-lg border p-3"
            >
              <strong>{rand(p.cents)}</strong> · {p.date} · {p.bank} ·{' '}
              <span className="font-mono">{p.reference}</span>
              <Badge tone={STATUS[p.status][1]}>{STATUS[p.status][0]}</Badge>
            </li>
          ))}
        </ul>
      )}
    </AgentLayout>
  );
}
