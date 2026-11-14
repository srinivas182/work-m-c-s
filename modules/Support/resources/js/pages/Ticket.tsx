import { Head, Link, useForm } from '@inertiajs/react';
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
  Textarea,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { StatusBadge, Thread, type ThreadMessage } from '../components/Thread';

interface Props {
  ticket: {
    number: number;
    subject: string;
    status: string;
    rating: string | null;
    opened: string | null;
    due: string | null;
  };
  messages: ThreadMessage[];
}

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/** One support ticket (Sprint 16.3): the conversation, a reply, and — once solved — how we did. */
export default function Ticket({ ticket, messages }: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  const list = base.replace(/\/\d+$/, '');
  const reply = useForm<{ body: string; screenshots: File[] }>({ body: '', screenshots: [] });
  const rate = useForm<{ rating: string; comment: string }>({ rating: '', comment: '' });
  return (
    <AppShell title={`Ticket #${ticket.number}`}>
      <Head title={`Ticket #${ticket.number}`} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          eyebrow={`Ticket #${ticket.number}`}
          title={ticket.subject}
          description={
            ticket.due ? `We aim to reply by ${when.format(new Date(ticket.due))}.` : undefined
          }
          actions={
            <Button asChild variant="secondary">
              <Link href={list}>All tickets</Link>
            </Button>
          }
        />
        <div>
          <StatusBadge status={ticket.status} />
        </div>
        <Thread messages={messages} fileUrl={(id) => `${base}/files/${id}`} />

        {ticket.status === 'solved' && !ticket.rating && (
          <Card>
            <CardHeader>
              <CardTitle>How did we do?</CardTitle>
            </CardHeader>
            <CardContent>
              <form
                aria-label="Rate this ticket"
                className="grid gap-3"
                onSubmit={(e) => {
                  e.preventDefault();
                  rate.post(`${base}/rate`, { preserveScroll: true });
                }}
              >
                <div className="flex gap-2" role="group" aria-label="Rating">
                  {(
                    [
                      { value: 'good', label: 'Good' },
                      { value: 'bad', label: 'Not good' },
                    ] as const
                  ).map(({ value, label }) => (
                    <Button
                      key={value}
                      type="button"
                      variant={rate.data.rating === value ? 'primary' : 'secondary'}
                      aria-pressed={rate.data.rating === value}
                      onClick={() => rate.setData('rating', value)}
                    >
                      {label}
                    </Button>
                  ))}
                </div>
                <Field label="Anything to add? (optional)" error={rate.errors.comment}>
                  <Input
                    maxLength={500}
                    value={rate.data.comment}
                    onChange={(e) => rate.setData('comment', e.target.value)}
                  />
                </Field>
                <Button
                  type="submit"
                  className="justify-self-start"
                  disabled={!rate.data.rating}
                  loading={rate.processing}
                >
                  Send rating
                </Button>
              </form>
            </CardContent>
          </Card>
        )}
        {ticket.rating && (
          <p className="text-fg-muted text-sm">
            Thanks — you rated this {ticket.rating === 'good' ? 'good' : 'not good'}.
          </p>
        )}

        <Card>
          <CardHeader>
            <CardTitle>
              {ticket.status === 'solved' ? 'Not solved? Reply to reopen it' : 'Reply'}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <form
              aria-label="Reply"
              className="grid gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                reply.post(`${base}/reply`, {
                  forceFormData: true,
                  preserveScroll: true,
                  onSuccess: () => reply.reset(),
                });
              }}
            >
              <Field label="Your reply" error={reply.errors.body}>
                <Textarea
                  rows={4}
                  maxLength={5000}
                  value={reply.data.body}
                  onChange={(e) => reply.setData('body', e.target.value)}
                />
              </Field>
              <Field label="Screenshots (optional)" hint="Up to 3 — PNG, JPG or WebP, 5 MB each.">
                <Input
                  type="file"
                  multiple
                  accept="image/png,image/jpeg,image/webp"
                  onChange={(e) =>
                    reply.setData('screenshots', Array.from(e.target.files ?? []).slice(0, 3))
                  }
                />
              </Field>
              <Button type="submit" className="justify-self-start" loading={reply.processing}>
                Send reply
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
