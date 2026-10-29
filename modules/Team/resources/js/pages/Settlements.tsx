import { Head, Link, router } from '@inertiajs/react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  EmptyState,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { ArrowLeftRight } from 'lucide-react';

interface Pair {
  from: string;
  to: string;
  from_name: string;
  to_name: string;
  total_cents: number;
  settled: boolean;
  entries: { kind: string; reference: string; note: string; amount_cents: number; at: string }[];
}

const rand = (c: number) => `${c < 0 ? '−' : ''}R${(Math.abs(c) / 100).toFixed(2)}`;

export default function Settlements({
  month,
  label,
  previous,
  next,
  pairs,
  canSettle,
}: {
  month: string;
  label: string;
  previous: string;
  next: string;
  pairs: Pair[];
  canSettle: boolean;
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  return (
    <AppShell title="Between branches">
      <Head title="Between branches" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Between branches"
          description="When one branch’s account is paid for something another branch supplied, the amount owed is recorded here. Settle it between your branches as you normally would, then mark it settled. No money moves through WURK."
        />
        <nav aria-label="Month" className="flex items-center gap-3 text-sm">
          <Link href={`${base}?month=${previous}`} className="underline">
            ← Previous month
          </Link>
          <strong>{label}</strong>
          <Link href={`${base}?month=${next}`} className="underline">
            Next month →
          </Link>
        </nav>
        {pairs.length === 0 ? (
          <EmptyState
            icon={ArrowLeftRight}
            title="Nothing owed this month"
            description="Branches were paid for what they supplied themselves."
          />
        ) : (
          pairs.map((p) => (
            <Card key={`${p.from}-${p.to}`}>
              <CardHeader>
                <CardTitle className="flex flex-wrap items-center gap-2">
                  {p.total_cents >= 0
                    ? `${p.from_name} owes ${p.to_name} ${rand(p.total_cents)}`
                    : `${p.to_name} owes ${p.from_name} ${rand(-p.total_cents)}`}
                  {p.settled && <Badge tone="success">Settled</Badge>}
                </CardTitle>
              </CardHeader>
              <CardContent className="grid gap-3">
                <table className="w-full text-left text-sm">
                  <caption className="sr-only">What makes up this amount</caption>
                  <thead>
                    <tr className="border-border border-b">
                      <th scope="col" className="py-2">
                        What
                      </th>
                      <th scope="col" className="py-2 text-right">
                        Amount
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {p.entries.map((e, k) => (
                      <tr key={k} className="border-border border-b">
                        <td className="py-2">{e.note}</td>
                        <td className="py-2 text-right">{rand(e.amount_cents)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {canSettle && !p.settled && (
                  <Button
                    variant="secondary"
                    className="justify-self-start"
                    onClick={() =>
                      router.post(
                        `${base}/settle`,
                        { month, from: p.from, to: p.to },
                        { preserveScroll: true },
                      )
                    }
                  >
                    Mark as settled
                  </Button>
                )}
              </CardContent>
            </Card>
          ))
        )}
      </div>
    </AppShell>
  );
}
