import { Head, Link } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { Badge } from '@/design-system/components/badge';
import { EmptyState, PageHeader } from '@/design-system/components/feedback';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/design-system/components/table';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

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
    <AppShell title={__('Failed payments')}>
      <Head title={__('Failed payments')} />
      <div className="grid gap-8">
        <PageHeader
          title={__('Failed payments')}
          description={__(
            'Businesses whose subscription payment failed, oldest first. Retries run automatically on the scheduled days.',
          )}
        />
        {rows.length === 0 ? (
          <EmptyState
            icon={CircleCheck}
            title={__('Everyone is paid up')}
            description={__('Businesses appear here when a payment fails.')}
          />
        ) : (
          <Table label={__('Failed payments')}>
            <TableHeader>
              <TableRow>
                <TableHead>{__('Business')}</TableHead>
                <TableHead>{__('Stage')}</TableHead>
                <TableHead className="text-right">{__('Day')}</TableHead>
                <TableHead>{__('Last failure')}</TableHead>
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
