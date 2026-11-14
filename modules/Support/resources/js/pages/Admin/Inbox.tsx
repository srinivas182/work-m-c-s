import { Head, Link } from '@inertiajs/react';
import { Inbox as InboxIcon } from 'lucide-react';
import { Badge, Button, Card, CardContent, EmptyState, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Props {
  status: string;
  statuses: Record<string, string>;
  counts: Record<string, number>;
  tickets: {
    number: number;
    subject: string;
    business: string;
    package: string | null;
    target_hours: number;
    due: string | null;
    late: boolean;
    rating: string | null;
    updated: string | null;
  }[];
}

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/** WURK's support inbox (Sprint 16.3): soonest due first; late first replies flagged. */
export default function Inbox({ status, statuses, counts, tickets }: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  return (
    <AppShell title="Support inbox">
      <Head title="Support inbox" />
      <div className="grid max-w-5xl gap-6">
        <PageHeader
          title="Support inbox"
          description="Tickets from every business. First replies are due within each package’s target, in business hours."
        />
        <nav aria-label="Ticket status" className="flex flex-wrap gap-2">
          {Object.entries(statuses).map(([key, label]) => (
            <Button key={key} asChild size="sm" variant={key === status ? 'primary' : 'secondary'}>
              <Link
                href={`${base}?status=${key}`}
                aria-current={key === status ? 'page' : undefined}
              >
                {label} ({counts[key] ?? 0})
              </Link>
            </Button>
          ))}
        </nav>
        {tickets.length === 0 ? (
          <EmptyState
            icon={InboxIcon}
            title="Nothing here"
            description="No tickets in this list."
          />
        ) : (
          <Card>
            <CardContent>
              <ul aria-label="Tickets" className="divide-border grid divide-y text-sm">
                {tickets.map((t) => (
                  <li key={t.number} className="flex flex-wrap items-center gap-3 py-3">
                    <Link className="font-semibold underline" href={`${base}/${t.number}`}>
                      #{t.number} {t.subject}
                    </Link>
                    <span className="text-fg-muted">
                      {t.business}
                      {t.package ? ` · ${t.package}` : ''} · {t.target_hours} h target
                    </span>
                    {t.late && <Badge tone="danger">First reply late</Badge>}
                    {t.rating && (
                      <Badge tone={t.rating === 'good' ? 'success' : 'danger'}>
                        Rated {t.rating}
                      </Badge>
                    )}
                    <span className="text-fg-muted ml-auto">
                      {t.due
                        ? `Reply by ${when.format(new Date(t.due))}`
                        : t.updated
                          ? when.format(new Date(t.updated))
                          : ''}
                    </span>
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
