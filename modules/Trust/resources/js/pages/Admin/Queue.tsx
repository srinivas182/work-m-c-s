import { Head, Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { EmptyState, PageHeader } from '@/design-system/components/feedback';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system/components/table';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Row {
  tenant: string;
  business: string;
  identity: string | null;
  registry: string | null;
}

/** Admin → Verification (Sprint 25.4): businesses waiting for an identity or registry check, oldest first. */
export default function Queue({ queue }: { queue: Row[] }) {
  return (
    <AppShell title={__('Verification')}>
      <Head title={__('Verification')} />
      <div className="grid gap-6">
        <PageHeader
          title={__('Verification')}
          description={__(
            'Owners’ identity documents and business registrations waiting for a check, oldest first.',
          )}
        />
        {queue.length === 0 ? (
          <EmptyState
            icon={ShieldCheck}
            title={__('Nothing waiting')}
            description={__('New checks appear here.')}
          />
        ) : (
          <Table label={__('Waiting for a check')}>
            <TableHeader>
              <TableRow>
                <TableHead>{__('Business')}</TableHead>
                <TableHead>{__('Identity sent')}</TableHead>
                <TableHead>{__('Registration sent')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {queue.map((r) => (
                <TableRow key={r.tenant}>
                  <TableCell>
                    <Link className="font-medium underline" href={`/verification/${r.tenant}`}>
                      {r.business}
                    </Link>
                  </TableCell>
                  <TableCell className="tabular-nums">{r.identity ?? '—'}</TableCell>
                  <TableCell className="tabular-nums">{r.registry ?? '—'}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </div>
    </AppShell>
  );
}
