import { Head, Link } from '@inertiajs/react';
import { LifeBuoy } from 'lucide-react';
import { Button, Card, CardContent, EmptyState, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { StatusBadge } from '../components/Thread';
import { __ } from '@/i18n';

interface Props {
  tickets: {
    number: number;
    subject: string;
    status: string;
    rated: boolean;
    opened: string | null;
    updated: string | null;
  }[];
  target: string;
}

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/** Your support tickets (Sprint 16.3). */
export default function Tickets({ tickets, target }: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  return (
    <AppShell title={__('Support')}>
      <Head title={__('Support')} />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title={__('Support')}
          description={`We aim to reply within ${target}.`}
          actions={
            <Button asChild>
              <Link href={`${base}/new`}>{__('Contact support')}</Link>
            </Button>
          }
        />
        {tickets.length === 0 ? (
          <EmptyState
            icon={LifeBuoy}
            title={__('No tickets yet')}
            description={__(
              'Stuck? Ask WURK or search help first — or contact us and we’ll get back to you.',
            )}
            action={
              <Button asChild variant="secondary">
                <Link href={`${base}/new`}>{__('Contact support')}</Link>
              </Button>
            }
          />
        ) : (
          <Card>
            <CardContent>
              <ul aria-label={__('Your tickets')} className="divide-border grid divide-y text-sm">
                {tickets.map((t) => (
                  <li key={t.number} className="flex flex-wrap items-center gap-3 py-3">
                    <Link className="font-semibold underline" href={`${base}/${t.number}`}>
                      #{t.number} {t.subject}
                    </Link>
                    <StatusBadge status={t.status} />
                    {t.status === 'solved' && !t.rated && (
                      <span className="text-fg-muted">{__('Tell us how we did')}</span>
                    )}
                    {t.updated && (
                      <span className="text-fg-muted ml-auto">
                        {when.format(new Date(t.updated))}
                      </span>
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
