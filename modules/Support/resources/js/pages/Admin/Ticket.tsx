import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
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
    priority: string;
    assignee_id: string | null;
    business: string;
    handle: string | null;
    app_domain: string;
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
  team: { id: string; name: string }[];
  priorities: Record<string, string>;
  canned: { id: string; title: string; body: string }[];
  access: {
    status: 'requested' | 'active';
    level: string;
    ends_at: string | null;
    mine: boolean;
  } | null;
}

function csrf(): string {
  return decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/**
 * A ticket in WURK's inbox (Sprint 16.3): the business's context, the conversation; assign, priority, internal notes;
 * reply from a canned reply or an AI-suggested draft (checked and edited first), or solve.
 */
export default function Ticket({ ticket, messages, team, priorities, canned, access }: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  const inbox = base.replace(/\/\d+$/, '');
  const reply = useForm<{ body: string; solve: boolean; canned_id: string | null }>({
    body: '',
    solve: false,
    canned_id: null,
  });
  const note = useForm({ body: '' });
  const ask = useForm<{ level: string; reason: string }>({ level: 'view', reason: '' });
  const [draft, setDraft] = useState<{
    answer: string | null;
    sources: { title: string; url: string }[];
    message?: string;
  } | null>(null);
  const [suggesting, setSuggesting] = useState(false);
  const firstName = ticket.opener.name.split(' ')[0] ?? '';
  const fill = (text: string) =>
    text.replaceAll('{name}', firstName).replaceAll('{business}', ticket.business);
  const suggest = async () => {
    setSuggesting(true);
    const r = await fetch(`${base}/suggest`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
    }).catch(() => null);
    setSuggesting(false);
    setDraft(
      r
        ? ((await r.json()) as typeof draft)
        : { answer: null, sources: [], message: 'Couldn’t reach WURK AI.' },
    );
  };
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
                <div className="flex flex-wrap items-end gap-2">
                  {canned.length > 0 && (
                    <Select
                      value=""
                      onValueChange={(id) => {
                        const c = canned.find((x) => x.id === id);
                        if (c) {
                          reply.setData({ ...reply.data, body: fill(c.body), canned_id: c.id });
                        }
                      }}
                    >
                      <SelectTrigger aria-label="Use a canned reply" className="w-56">
                        <SelectValue placeholder="Use a canned reply" />
                      </SelectTrigger>
                      <SelectContent>
                        {canned.map((c) => (
                          <SelectItem key={c.id} value={c.id}>
                            {c.title}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                  <Button
                    type="button"
                    variant="secondary"
                    loading={suggesting}
                    onClick={() => void suggest()}
                  >
                    Suggest a reply
                  </Button>
                </div>
                {draft && (
                  <div
                    className="border-border grid gap-2 rounded-lg border p-3 text-sm"
                    aria-label="Suggested reply"
                  >
                    {draft.answer ? (
                      <>
                        <p className="whitespace-pre-line">{draft.answer}</p>
                        {draft.sources.length > 0 && (
                          <p className="text-fg-muted">
                            From:{' '}
                            {draft.sources.map((src, i) => (
                              <span key={src.url}>
                                {i > 0 && ', '}
                                <a
                                  className="underline"
                                  href={src.url}
                                  target="_blank"
                                  rel="noreferrer"
                                >
                                  {src.title}
                                </a>
                              </span>
                            ))}
                          </p>
                        )}
                        <Button
                          type="button"
                          size="sm"
                          className="justify-self-start"
                          onClick={() => {
                            reply.setData({
                              ...reply.data,
                              body: draft.answer ?? '',
                              canned_id: null,
                            });
                            setDraft(null);
                          }}
                        >
                          Use this draft
                        </Button>
                      </>
                    ) : (
                      <p className="text-fg-muted">{draft.message ?? 'No suggestion.'}</p>
                    )}
                  </div>
                )}
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
          <Card>
            <CardHeader>
              <CardTitle>Internal note</CardTitle>
            </CardHeader>
            <CardContent>
              <form
                aria-label="Internal note"
                className="grid gap-3"
                onSubmit={(e) => {
                  e.preventDefault();
                  note.post(`${base}/note`, {
                    preserveScroll: true,
                    onSuccess: () => note.reset(),
                  });
                }}
              >
                <Field label="Note (only WURK sees it)" error={note.errors.body}>
                  <Textarea
                    rows={3}
                    value={note.data.body}
                    onChange={(e) => note.setData('body', e.target.value)}
                  />
                </Field>
                <Button
                  type="submit"
                  variant="secondary"
                  className="justify-self-start"
                  loading={note.processing}
                >
                  Add note
                </Button>
              </form>
            </CardContent>
          </Card>
        </div>
        <aside className="grid content-start gap-4 text-sm" aria-label="Ticket details">
          <Card aria-label="Back-office access">
            <CardHeader>
              <CardTitle>Their back office</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3">
              {access?.status === 'active' ? (
                <>
                  <p>
                    {access.level === 'full' ? 'Full' : 'View-only'} access until{' '}
                    {access.ends_at ? when.format(new Date(access.ends_at)) : '—'}.
                  </p>
                  {access.mine && ticket.handle && (
                    <Button asChild size="sm">
                      <a
                        href={`${window.location.protocol}//${ticket.app_domain}/${ticket.handle}`}
                      >
                        Open their back office
                      </a>
                    </Button>
                  )}
                  {access.mine && (
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() =>
                        router.post(`${base}/access/end`, {}, { preserveScroll: true })
                      }
                    >
                      I’m done — end access
                    </Button>
                  )}
                </>
              ) : access?.status === 'requested' ? (
                <p>
                  Asked for {access.level === 'full' ? 'full' : 'view-only'} access — waiting for
                  the business to decide.
                </p>
              ) : (
                <form
                  aria-label="Ask for access"
                  className="grid gap-2"
                  onSubmit={(e) => {
                    e.preventDefault();
                    ask.post(`${base}/access`, {
                      preserveScroll: true,
                      onSuccess: () => ask.reset(),
                    });
                  }}
                >
                  <Select value={ask.data.level} onValueChange={(v) => ask.setData('level', v)}>
                    <SelectTrigger aria-label="Access to ask for">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="view">View only</SelectItem>
                      <SelectItem value="full">Full (owner decides)</SelectItem>
                    </SelectContent>
                  </Select>
                  <Field label="Why (shown to the business)" error={ask.errors.reason}>
                    <Textarea
                      rows={2}
                      value={ask.data.reason}
                      onChange={(e) => ask.setData('reason', e.target.value)}
                    />
                  </Field>
                  <Button type="submit" size="sm" variant="secondary" loading={ask.processing}>
                    Ask for access
                  </Button>
                </form>
              )}
            </CardContent>
          </Card>
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
              <Select
                value={ticket.assignee_id ?? 'nobody'}
                onValueChange={(v) =>
                  router.post(
                    `${base}/assign`,
                    { assignee_id: v === 'nobody' ? null : v },
                    { preserveScroll: true },
                  )
                }
              >
                <SelectTrigger aria-label="Assigned to">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="nobody">Unassigned</SelectItem>
                  {team.map((m) => (
                    <SelectItem key={m.id} value={m.id}>
                      {m.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Select
                value={ticket.priority}
                onValueChange={(v) =>
                  router.post(`${base}/priority`, { priority: v }, { preserveScroll: true })
                }
              >
                <SelectTrigger aria-label="Priority">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {Object.entries(priorities).map(([k, l]) => (
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
