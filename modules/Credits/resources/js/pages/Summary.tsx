import { Coins } from 'lucide-react';
import {
  Badge,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  EmptyState,
  StatCard,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system';

export interface CreditSummary {
  randPerCredit?: number | null;
  nextPayment?: { date: string; total_cents: number } | null;
  allowanceExpires?: string | null;
  included?: number;
  spentThisMonth?: number;
  meters?: { label: string; used: number; included: number | null; unit: string }[];
  limits?: { monthly: number | null };
  canManage?: boolean;
  balance: number;
  allowance: number;
  lowBalance: number;
  rates: Record<string, { label: string; credits: number | null }>;
  usage: { channel: string; messages: number; credits: number }[];
  ledger: { at: string; type: string; description: string; credits: number; balance: number }[];
}

export const credits = (n: number) => n.toLocaleString(undefined, { maximumFractionDigits: 1 });

const TYPE: Record<string, string> = {
  allowance: 'Monthly allowance',
  purchase: 'Purchase',
  adjustment: 'Adjustment',
  debit: 'Used',
  refund: 'Refund',
  expiry: 'Expired',
};

/** Balance cards, this month's usage and the ledger — shared by the owner and admin views. */
export function CreditOverview({ summary }: { summary: CreditSummary }) {
  return (
    <div className="grid gap-6">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard
          label="Balance"
          value={credits(summary.balance)}
          icon={Coins}
          hint={summary.balance < summary.lowBalance ? 'Below your alert level' : undefined}
        />
        <StatCard
          label="Of which monthly allowance"
          value={credits(summary.allowance)}
          hint="Used first; unused allowance expires at the next one"
        />
        <StatCard
          label="Used this month"
          value={credits(summary.usage.reduce((t, u) => t + u.credits, 0))}
          hint={`${summary.usage.reduce((t, u) => t + u.messages, 0)} messages`}
        />
      </div>
      <Card>
        <CardHeader>
          <CardTitle>What things cost</CardTitle>
          <CardDescription>Credits per message or action.</CardDescription>
        </CardHeader>
        <CardContent>
          <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
            {Object.values(summary.rates).map((r) => (
              <div key={r.label}>
                <dt className="text-fg-muted">{r.label}</dt>
                <dd className="font-medium tabular-nums">
                  {r.credits === null ? '—' : credits(r.credits)}
                </dd>
              </div>
            ))}
          </dl>
        </CardContent>
      </Card>
      {summary.ledger.length === 0 ? (
        <EmptyState
          icon={Coins}
          title="No credit activity yet"
          description="Allowances, purchases and usage will appear here."
        />
      ) : (
        <Table label="Credit activity">
          <TableHeader>
            <TableRow>
              <TableHead>When</TableHead>
              <TableHead>What</TableHead>
              <TableHead className="text-right">Credits</TableHead>
              <TableHead className="text-right">Balance</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {summary.ledger.map((e, i) => (
              <TableRow key={i}>
                <TableCell className="text-fg-muted">{new Date(e.at).toLocaleString()}</TableCell>
                <TableCell>
                  <Badge tone={e.credits < 0 ? 'neutral' : 'success'}>
                    {TYPE[e.type] ?? e.type}
                  </Badge>{' '}
                  <span className="ml-1">{e.description}</span>
                </TableCell>
                <TableCell className="text-right tabular-nums">
                  {e.credits > 0 ? '+' : ''}
                  {credits(e.credits)}
                </TableCell>
                <TableCell className="text-right tabular-nums">{credits(e.balance)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </div>
  );
}
