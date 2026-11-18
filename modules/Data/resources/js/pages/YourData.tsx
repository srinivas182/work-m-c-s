import { Head, router } from '@inertiajs/react';
import { Database } from 'lucide-react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/design-system/components/card';
import { EmptyState, PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Export {
  id: string;
  status: 'queued' | 'ready' | 'failed' | 'expired';
  size: number;
  contents: { tables?: Record<string, number>; files?: Record<string, number> } | null;
  requested: string | null;
  expires: string | null;
  download: string | null;
}

interface Props {
  hours: number;
  exports: Export[];
}

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const label = {
  queued: 'Being built',
  ready: 'Ready',
  failed: 'Didn’t work',
  expired: 'Expired',
} as const;
const tone = { queued: 'info', ready: 'success', failed: 'danger', expired: 'neutral' } as const;

function size(bytes: number): string {
  return bytes >= 1_048_576
    ? `${(bytes / 1_048_576).toFixed(1)} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** Settings → Your data (Sprint 16.6): everything you keep in WURK, in one download. */
export default function YourData({ hours, exports }: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  return (
    <AppShell title={__('Your data')}>
      <Head title={__('Your data')} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title={__('Your data')}
          description={__('It’s yours. Download everything you keep in WURK, any time.')}
        />
        <Card>
          <CardHeader>
            <CardTitle>{__('Export everything')}</CardTitle>
            <CardDescription>
              {__(
                'A ZIP with a spreadsheet (CSV) for each part of your business — customers, orders, products, bookings, campaigns, website pages and more — and your files. We build it in the background and email you a link that works for',
              )}{' '}
              {hours} {__('hours.')}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-3 text-sm">
            <p className="text-fg-muted">
              {__(
                'It holds your customers’ personal information — keep it somewhere safe. Patient records are only included for people allowed to see them.',
              )}
            </p>
            <Button
              className="justify-self-start"
              onClick={() => router.post(`${base}/export`, {}, { preserveScroll: true })}
            >
              {__('Export everything')}
            </Button>
          </CardContent>
        </Card>
        {exports.length === 0 ? (
          <EmptyState
            icon={Database}
            title={__('No exports yet')}
            description={__('Your exports appear here while they can be downloaded.')}
          />
        ) : (
          <Card>
            <CardHeader>
              <CardTitle>{__('Your exports')}</CardTitle>
            </CardHeader>
            <CardContent>
              <ul aria-label={__('Your exports')} className="divide-border grid divide-y text-sm">
                {exports.map((e) => (
                  <li key={e.id} className="grid gap-1 py-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <span>{e.requested ? when.format(new Date(e.requested)) : ''}</span>
                      <Badge tone={tone[e.status]}>{label[e.status]}</Badge>
                      {e.status === 'ready' && (
                        <span className="text-fg-muted">{size(e.size)}</span>
                      )}
                      {e.download && (
                        <a className="ml-auto underline" href={e.download}>
                          {__('Download')}
                        </a>
                      )}
                    </div>
                    {e.contents?.tables && (
                      <p className="text-fg-muted">
                        {Object.entries(e.contents.tables)
                          .map(([k, n]) => `${k} (${n})`)
                          .join(' · ')}
                      </p>
                    )}
                    {e.status === 'ready' && e.expires && (
                      <p className="text-fg-muted">
                        {__('Until')} {when.format(new Date(e.expires))}
                      </p>
                    )}
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
