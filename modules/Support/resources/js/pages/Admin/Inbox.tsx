import { Head, Link } from '@inertiajs/react';
import { Inbox as InboxIcon } from 'lucide-react';
import { Badge, Button, Card, CardContent, EmptyState, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Props {
  status: string;
  view: string;
  priority: string;
  statuses: Record<string, string>;
  views: Record<string, string>;
  priorities: Record<string, string>;
  counts: Record<string, number>;
  late: number;
  tickets: {
    number: number;
    subject: string;
    business: string;
    package: string | null;
    target_hours: number;
    priority: string;
    assignee: string | null;
    due: string | null;
    late: boolean;
    rating: string | null;
    updated: string | null;
  }[];
}

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/** WURK's support inbox (Sprint 16.3): urgent first, then soonest due; views and priority filters; late first replies flagged. */
export default function Inbox({
  status,
  view,
  priority,
  statuses,
  views,
  priorities,
  counts,
  late,
  tickets,
}: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  const href = (over: Record<string, string>) =>
    `${base}?${new URLSearchParams({ status, view, ...(priority ? { priority } : {}), ...over }).toString()}`;
  return (
    <AppShell title="Support inbox">
      <Head title="Support inbox" />
      <div className="grid max-w-5xl gap-6">
        <PageHeader
          title="Support inbox"
          description="Tickets from every business — urgent first, then soonest due. First replies are due within each package’s target, in business hours."
          actions={
            <Button asChild variant="secondary">
              <Link href={`${base}/settings`}>Targets and canned replies</Link>
            </Button>
          }
        />
        {late > 0 && (
          <p role="status" className="text-danger-11 text-sm font-medium">
            {late} {late === 1 ? 'ticket is' : 'tickets are'} past the first-reply target.{' '}
            <Link className="underline" href={href({ status: 'open', view: 'late' })}>
              Show them
            </Link>
          </p>
        )}
        <nav aria-label="Ticket status" className="flex flex-wrap gap-2">
          {Object.entries(statuses).map(([key, label]) => (
            <Button key={key} asChild size="sm" variant={key === status ? 'primary' : 'secondary'}>
              <Link href={href({ status: key })} aria-current={key === status ? 'page' : undefined}>
                {label} ({counts[key] ?? 0})
              </Link>
            </Button>
          ))}
        </nav>
        <nav aria-label="Show" className="flex flex-wrap gap-2">
          {Object.entries(views).map(([key, label]) => (
            <Button key={key} asChild size="sm" variant={key === view ? 'soft' : 'ghost'}>
              <Link href={href({ view: key })} aria-current={key === view ? 'page' : undefined}>
                {label}
              </Link>
            </Button>
          ))}
          <span className="text-fg-muted self-center text-sm">·</span>
          {([['', 'Any priority'], ...Object.entries(priorities)] as [string, string][]).map(
            ([key, label]) => (
              <Button
                key={key || 'any'}
                asChild
                size="sm"
                variant={key === priority ? 'soft' : 'ghost'}
              >
                <Link
                  href={href({ priority: key })}
                  aria-current={key === priority ? 'page' : undefined}
                >
                  {label}
                </Link>
              </Button>
            ),
          )}
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
                    {t.priority !== 'normal' && (
                      <Badge
                        tone={
                          t.priority === 'urgent'
                            ? 'danger'
                            : t.priority === 'high'
                              ? 'warning'
                              : 'neutral'
                        }
                      >
                        {priorities[t.priority] ?? t.priority}
                      </Badge>
                    )}
                    {t.late && <Badge tone="danger">First reply late</Badge>}
                    <span className="text-fg-muted">{t.assignee ?? 'Unassigned'}</span>
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
