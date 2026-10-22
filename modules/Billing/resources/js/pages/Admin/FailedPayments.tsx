import { Head, Link } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import {
  Badge,
  EmptyState,
  PageHeader,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Props {
  rows: {
    id: string;
    name: string;
    stage: string;
    day: number | null;
    since: string;
    last_failure: string | null;
  }[];
}

const STAGE: Record<string, [string, 'warning' | 'danger']> = {
  grace: ['Grace period', 'warning'],
  restricted: ['Billing only', 'danger'],
  suspended: ['Suspended', 'danger'],
};

export default function FailedPayments({ rows }: Props) {
  return (
    <AppShell title="Failed payments">
      <Head title="Failed payments" />
      <div className="grid gap-8">
        <PageHeader
          title="Failed payments"
          description="Businesses whose subscription payment failed, oldest first. Retries run automatically on the scheduled days."
        />
        {rows.length === 0 ? (
          <EmptyState
            icon={CircleCheck}
            title="Everyone is paid up"
            description="Businesses appear here when a payment fails."
          />
        ) : (
          <Table label="Failed payments">
            <TableHeader>
              <TableRow>
                <TableHead>Business</TableHead>
                <TableHead>Stage</TableHead>
                <TableHead className="text-right">Day</TableHead>
                <TableHead>Last failure</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((r) => (
                <TableRow key={r.id}>
                  <TableCell className="font-medium">
                    <Link
                      href={`/businesses/${r.id}/billing`}
                      className="underline-offset-4 hover:underline"
                    >
                      {r.name}
                    </Link>
                  </TableCell>
                  <TableCell>
                    <Badge tone={STAGE[r.stage]?.[1] ?? 'neutral'} dot>
                      {STAGE[r.stage]?.[0] ?? r.stage}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{r.day ?? '—'}</TableCell>
                  <TableCell className="text-fg-muted">{r.last_failure ?? '—'}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </div>
    </AppShell>
  );
}
