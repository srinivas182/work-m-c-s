import { Head, Link, router } from '@inertiajs/react';
import { Globe } from 'lucide-react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent } from '@/design-system/components/card';
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

interface Domain {
  id: string;
  host: string;
  status: 'pending' | 'active' | 'lapsed';
  ssl: string | null;
  primary: boolean;
  problem: string | null;
  checked_at: string | null;
  business: string | null;
  business_id: string;
}

interface Props {
  configured: boolean;
  target: string;
  counts: Record<string, number>;
  domains: Domain[];
}

const tone = (s: Domain['status']) =>
  s === 'active' ? 'success' : s === 'lapsed' ? 'danger' : 'warning';
const label = (s: Domain['status']) =>
  s === 'active' ? __('Working') : s === 'lapsed' ? __('Stopped') : __('Waiting');

/** Admin → Domains (Sprint 17.3): owners' own domains across WURK — stopped first, then waiting, then working. */
export default function Domains({ configured, target, counts, domains }: Props) {
  return (
    <AppShell title={__('Domains')}>
      <Head title={__('Domains')} />
      <div className="grid max-w-6xl gap-6">
        <PageHeader
          title={__('Domains')}
          description={`${__('Businesses’ own domains. Owners point them at')} ${target}.`}
        />
        {!configured && (
          <p role="alert" className="text-warning-11 text-sm">
            {__(
              'The domain service isn’t set up (CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID). Owners can’t add domains until it is.',
            )}
          </p>
        )}
        <p className="text-fg-muted text-sm">
          {counts.active ?? 0} {__('working')} · {counts.pending ?? 0} {__('waiting')} ·{' '}
          {counts.lapsed ?? 0} {__('stopped')}
        </p>
        {domains.length === 0 ? (
          <EmptyState
            icon={Globe}
            title={__('No businesses have their own domain yet.')}
            description={__('Owners add theirs in Website → Your domain.')}
          />
        ) : (
          <Card>
            <CardContent className="overflow-x-auto pt-6">
              <Table label={__('Businesses’ own domains')}>
                <TableHeader>
                  <TableRow>
                    <TableHead>{__('Domain')}</TableHead>
                    <TableHead>{__('Business')}</TableHead>
                    <TableHead>{__('State')}</TableHead>
                    <TableHead>{__('Last checked')}</TableHead>
                    <TableHead>
                      <span className="sr-only">{__('Actions')}</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {domains.map((d) => (
                    <TableRow key={d.id}>
                      <TableCell>
                        <span className="font-medium">{d.host}</span>
                        {d.primary && (
                          <span className="text-fg-muted"> · {__('main address')}</span>
                        )}
                        {d.problem && <p className="text-fg-muted text-sm">{d.problem}</p>}
                      </TableCell>
                      <TableCell>
                        <Link className="underline" href={`/businesses/${d.business_id}`}>
                          {d.business ?? d.business_id}
                        </Link>
                      </TableCell>
                      <TableCell>
                        <Badge tone={tone(d.status)}>{label(d.status)}</Badge>
                      </TableCell>
                      <TableCell className="tabular-nums">
                        {d.checked_at ? new Date(d.checked_at).toLocaleString() : '—'}
                      </TableCell>
                      <TableCell>
                        <Button
                          size="sm"
                          variant="secondary"
                          disabled={!configured}
                          onClick={() =>
                            router.post(`/domains/${d.id}/check`, {}, { preserveScroll: true })
                          }
                        >
                          {__('Check now')}
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
