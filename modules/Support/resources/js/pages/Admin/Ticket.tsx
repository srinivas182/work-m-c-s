import { Head, Link, router, useForm } from '@inertiajs/react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Field,
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Textarea,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { StatusBadge, Thread, type ThreadMessage } from '../../components/Thread';

interface Props {
  ticket: {
    number: number;
    subject: string;
    status: string;
    statuses: Record<string, string>;
    business: string;
    handle: string | null;
    package: string | null;
    target_hours: number;
    due: string;
    responded: string | null;
    late: boolean;
    page: string | null;
    browser: string | null;
    last_error: string | null;
    rating: string | null;
    rating_comment: string | null;
    opener: { name: string; email: string | null; phone: string | null };
  };
  messages: ThreadMessage[];
}

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/** A ticket in WURK's inbox (Sprint 16.3): the business's context, the conversation, reply or solve. */
export default function Ticket({ ticket, messages }: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  const inbox = base.replace(/\/\d+$/, '');
  const reply = useForm<{ body: string; solve: boolean }>({ body: '', solve: false });
  const send = (solve: boolean) => {
    reply.transform((d) => ({ ...d, solve }));
    reply.post(`${base}/reply`, { preserveScroll: true, onSuccess: () => reply.reset() });
  };
  return (
    <AppShell title={`#${ticket.number}`}>
      <Head title={`#${ticket.number} ${ticket.subject}`} />
      <div className="grid max-w-5xl gap-6 lg:grid-cols-[1fr_18rem]">
        <div className="grid content-start gap-6">
          <PageHeader
            eyebrow={`#${ticket.number} · ${ticket.business}`}
            title={ticket.subject}
            actions={
              <Button asChild variant="secondary">
                <Link href={inbox}>Inbox</Link>
              </Button>
            }
          />
          <Thread messages={messages} fileUrl={(id) => `${base}/files/${id}`} />
          <Card>
            <CardHeader>
              <CardTitle>Reply to {ticket.opener.name}</CardTitle>
            </CardHeader>
            <CardContent>
              <form
                aria-label="Reply"
                className="grid gap-3"
                onSubmit={(e) => {
                  e.preventDefault();
                  send(false);
                }}
              >
                <Field label="Reply (emailed to the business)" error={reply.errors.body}>
                  <Textarea
                    rows={6}
                    value={reply.data.body}
                    onChange={(e) => reply.setData('body', e.target.value)}
                  />
                </Field>
                <div className="flex flex-wrap gap-2">
                  <Button type="submit" loading={reply.processing}>
                    Reply
                  </Button>
                  <Button
                    type="button"
                    variant="secondary"
                    disabled={reply.processing}
                    onClick={() => send(true)}
                  >
                    Reply and solve
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
        </div>
        <aside className="grid content-start gap-4 text-sm" aria-label="Ticket details">
          <Card>
            <CardContent className="grid gap-3 pt-6">
              <div className="flex flex-wrap items-center gap-2">
                <StatusBadge status={ticket.status} labels={ticket.statuses} />
                {ticket.late && <Badge tone="danger">First reply late</Badge>}
              </div>
              <Select
                value={ticket.status}
                onValueChange={(v) =>
                  router.post(`${base}/status`, { status: v }, { preserveScroll: true })
                }
              >
                <SelectTrigger aria-label="Status">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {Object.entries(ticket.statuses).map(([k, l]) => (
                    <SelectItem key={k} value={k}>
                      {l}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <dl className="grid gap-2">
                <div>
                  <dt className="text-fg-muted">Business</dt>
                  <dd>
                    {ticket.business}
                    {ticket.package ? ` · ${ticket.package}` : ''}
                  </dd>
                </div>
                <div>
                  <dt className="text-fg-muted">First reply</dt>
                  <dd>
                    {ticket.responded
                      ? `Sent ${when.format(new Date(ticket.responded))}`
                      : `Due ${when.format(new Date(ticket.due))}`}{' '}
                    ({ticket.target_hours} business hours)
                  </dd>
                </div>
                <div>
                  <dt className="text-fg-muted">From</dt>
                  <dd>
                    {ticket.opener.name}
                    {ticket.opener.email && <div>{ticket.opener.email}</div>}
                    {ticket.opener.phone && <div>{ticket.opener.phone}</div>}
                  </dd>
                </div>
                {ticket.page && (
                  <div>
                    <dt className="text-fg-muted">Page</dt>
                    <dd className="break-all">{ticket.page}</dd>
                  </div>
                )}
                {ticket.last_error && (
                  <div>
                    <dt className="text-fg-muted">Last error</dt>
                    <dd className="break-words">{ticket.last_error}</dd>
                  </div>
                )}
                {ticket.browser && (
                  <div>
                    <dt className="text-fg-muted">Browser</dt>
                    <dd className="break-words">{ticket.browser}</dd>
                  </div>
                )}
                {ticket.rating && (
                  <div>
                    <dt className="text-fg-muted">Rating</dt>
                    <dd>
                      {ticket.rating === 'good' ? 'Good' : 'Not good'}
                      {ticket.rating_comment ? ` — “${ticket.rating_comment}”` : ''}
                    </dd>
                  </div>
                )}
              </dl>
            </CardContent>
          </Card>
        </aside>
      </div>
    </AppShell>
  );
}
