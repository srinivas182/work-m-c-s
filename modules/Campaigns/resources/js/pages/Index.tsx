import { Head, Link } from '@inertiajs/react';
import { Megaphone } from 'lucide-react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { EmptyState, PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';
import { __ } from '@/i18n';

interface Campaign {
  id: string;
  name: string;
  channel: 'email' | 'sms';
  status: 'draft' | 'scheduled' | 'sending' | 'sent';
  scheduled_at: string | null;
  sent_at: string | null;
  sent: number;
  failed: number;
  total: number;
  opened: number;
  clicked: number;
  redeemed: number;
  sales_cents: number;
}
interface Ready {
  name: string;
  channel: string;
  about: string;
}

/** Customers → Campaigns. */
export default function Index({
  campaigns,
  readyMade,
}: {
  campaigns: Campaign[];
  readyMade: Record<string, Ready>;
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  return (
    <AppShell title={__('Campaigns')}>
      <Head title={__('Campaigns')} />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title={__('Campaigns')}
          description={__(
            'Email or SMS to customers who agreed to hear from you. Every message has an opt-out.',
          )}
          actions={
            <Button asChild>
              <Link href={`${base}/new`}>{__('New campaign')}</Link>
            </Button>
          }
        />
        <Card>
          <CardHeader>
            <CardTitle>{__('Ready-made')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-2 text-sm sm:grid-cols-2">
            {Object.entries(readyMade).map(([key, r]) => (
              <Link
                key={key}
                href={`${base}/new?ready=${key}`}
                className="border-border hover:bg-surface rounded-lg border p-3"
              >
                <strong>{r.name}</strong>{' '}
                <span className="text-fg-muted">({r.channel === 'sms' ? 'SMS' : 'email'})</span>
                <p className="text-fg-muted">{r.about}</p>
              </Link>
            ))}
          </CardContent>
        </Card>
        {campaigns.length === 0 ? (
          <EmptyState
            icon={Megaphone}
            title={__('No campaigns yet')}
            description={__('Start with a ready-made one above.')}
          />
        ) : (
          <ul aria-label={__('Campaigns')} className="grid gap-2">
            {campaigns.map((c) => (
              <li
                key={c.id}
                className="border-border flex flex-wrap items-center gap-3 rounded-lg border p-3 text-sm"
              >
                <Link className="font-semibold underline" href={`${base}/${c.id}`}>
                  {c.name}
                </Link>
                <span>{c.channel === 'sms' ? 'SMS' : 'Email'}</span>
                <Badge
                  tone={
                    c.status === 'sent'
                      ? 'success'
                      : c.status === 'scheduled'
                        ? 'warning'
                        : 'neutral'
                  }
                >
                  {c.status === 'sent'
                    ? 'Sent'
                    : c.status === 'scheduled'
                      ? 'Scheduled'
                      : c.status === 'sending'
                        ? 'Sending'
                        : 'Draft'}
                </Badge>
                {c.status === 'sending' && (
                  <span className="text-fg-muted" aria-live="polite">
                    {c.total > 0
                      ? `${c.sent + c.failed} of ${c.total} done`
                      : 'Getting the list ready…'}
                  </span>
                )}
                {c.status === 'sent' && (
                  <span className="text-fg-muted">
                    {c.sent} {__('sent')}
                    {c.failed > 0 ? ` · ${c.failed} failed` : ''}
                    {c.channel === 'email' ? ` · ${c.opened} opened` : ''} · {c.clicked}{' '}
                    {__('clicked')}
                    {c.redeemed > 0
                      ? ` · ${c.redeemed} redeemed · ${rand(c.sales_cents)} in sales`
                      : ''}
                  </span>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    </AppShell>
  );
}
