import { Badge, EmptyState } from '@/design-system';
import { Store } from 'lucide-react';
import { rand } from '@/lib/money';
import { AgentLayout } from '../../components/AgentLayout';

interface Business {
  id: string;
  name: string;
  joined: string;
  package: string | null;
  status: 'trial' | 'new' | 'active' | 'at_risk' | 'churned';
  monthly_cents: number;
  earned_cents: number;
}

const STATUS = {
  trial: { label: 'Trial', tone: 'neutral' },
  new: { label: 'New — first payment due', tone: 'neutral' },
  active: { label: 'Active', tone: 'success' },
  at_risk: { label: 'At risk — payment overdue', tone: 'danger' },
  churned: { label: 'Churned', tone: 'neutral' },
} as const;

/** The businesses the agent brought to WURK — at-risk first, so they can help keep them. */
export default function AgentBusinesses({ businesses }: { businesses: Business[] }) {
  return (
    <AgentLayout title="My businesses">
      {businesses.length === 0 ? (
        <EmptyState
          icon={Store}
          title="No businesses yet"
          description="Businesses that sign up through your link appear here."
        />
      ) : (
        <ul aria-label="My businesses" className="grid gap-3">
          {businesses.map((b) => (
            <li key={b.id} className="border-border grid gap-1 rounded-lg border p-4 text-sm">
              <div className="flex flex-wrap items-center gap-2">
                <strong>{b.name}</strong>
                <Badge tone={STATUS[b.status].tone}>{STATUS[b.status].label}</Badge>
              </div>
              <p className="text-fg-muted">
                Joined {b.joined}
                {b.package ? ` · ${b.package} · ${rand(b.monthly_cents)} a month` : ''} · you’ve
                earned {rand(b.earned_cents)} from it
              </p>
            </li>
          ))}
        </ul>
      )}
    </AgentLayout>
  );
}
