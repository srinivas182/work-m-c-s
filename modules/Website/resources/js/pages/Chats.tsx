import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { useState } from 'react';
import { Badge, Button, EmptyState, Field, Input, PageHeader, Textarea } from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface ChatRow {
  id: string;
  name: string;
  phone: string | null;
  email: string | null;
  message: string | null;
  transcript: { from: 'visitor' | 'bot'; text: string }[];
  status: 'new' | 'read' | 'archived';
  at: string;
  contact_id: string | null;
  lead_id: string | null;
}

/** An AI assistant conversation with a customer (19.4b). */
interface Conversation {
  id: string;
  name: string | null;
  phone: string | null;
  email: string | null;
  status: 'ai' | 'waiting' | 'person' | 'closed';
  outcome: 'answered' | 'booked' | 'ordered' | 'handed_over' | null;
  at: string;
  replies: number;
  contact_id: string | null;
  /** 'whatsapp' for WhatsApp conversations (21.2); web chats otherwise. */
  channel?: string;
  /** WhatsApp: free-form replies are allowed until 24 hours after the customer's last message. */
  window_open?: boolean | null;
  window_ends?: string | null;
  lines: {
    from: 'customer' | 'assistant' | 'person';
    text: string;
    delivery?: 'sent' | 'delivered' | 'read' | 'failed' | null;
    error?: string | null;
    file?: { url: string; name: string; kind: string } | { blocked: string } | null;
  }[];
}
type Show = 'open' | 'archived' | 'ai' | 'whatsapp';

const DELIVERY: Record<string, string> = {
  sent: 'Sent',
  delivered: 'Delivered',
  read: 'Read',
  failed: 'Not delivered',
};
const BLOCKED: Record<string, string> = {
  infected: 'A file that failed the virus check (not kept)',
  too_large: 'A file too large to keep',
  unavailable: 'A file WhatsApp no longer has',
};

const OUTCOME: Record<string, [string, 'success' | 'info' | 'warning' | 'neutral']> = {
  booked: ['Booked', 'success'],
  ordered: ['Ordered', 'success'],
  handed_over: ['Passed to you', 'warning'],
  answered: ['Answered', 'neutral'],
};
const STATUS: Record<Conversation['status'], string> = {
  ai: 'The assistant is answering',
  waiting: 'Waiting for you',
  person: 'You have this chat',
  closed: 'Closed',
};

export default function Chats({
  chats,
  show,
  assistant = false,
  whatsapp = false,
  conversations = [],
}: {
  chats: ChatRow[];
  show: Show;
  assistant?: boolean;
  whatsapp?: boolean;
  conversations?: Conversation[];
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  const appBase = `/${window.location.pathname.split('/')[1] ?? ''}`;
  const status = (c: ChatRow, to: ChatRow['status']) =>
    router.put(`${base}/${c.id}`, { status: to }, { preserveScroll: true });
  return (
    <AppShell title="Chats">
      <Head title="Chats" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Chats"
          description="People who asked, in your website chat, for you to get back to them — with the conversation."
        />
        <nav aria-label="Which chats" className="flex flex-wrap gap-4 text-sm">
          {(
            [
              ['open', 'Asked for a person', base],
              ...(whatsapp ? [['whatsapp', 'WhatsApp', `${base}?show=whatsapp`] as const] : []),
              ...(assistant
                ? [['ai', 'AI assistant conversations', `${base}?show=ai`] as const]
                : []),
              ['archived', 'Archived', `${base}?show=archived`],
            ] as const
          ).map(([key, label, href]) => (
            <Link
              key={key}
              href={href}
              className={show === key ? 'font-semibold' : 'underline'}
              aria-current={show === key ? 'page' : undefined}
            >
              {label}
            </Link>
          ))}
        </nav>
        {show === 'ai' || show === 'whatsapp' ? (
          conversations.length === 0 ? (
            <EmptyState
              icon={MessageCircle}
              title="No conversations yet"
              description={
                show === 'whatsapp'
                  ? 'When customers message your WhatsApp number, the conversations appear here.'
                  : 'When customers chat with your AI assistant, the conversations appear here.'
              }
            />
          ) : (
            <ul
              aria-label={
                show === 'whatsapp' ? 'WhatsApp conversations' : 'AI assistant conversations'
              }
              className="grid gap-4"
            >
              {conversations.map((c) => (
                <ConversationItem key={c.id} c={c} base={base} appBase={appBase} />
              ))}
            </ul>
          )
        ) : chats.length === 0 ? (
          <EmptyState
            icon={MessageCircle}
            title="No chats yet"
            description="When someone in your website chat asks for a person, it appears here."
          />
        ) : (
          <ul aria-label="Chats" className="grid gap-4">
            {chats.map((c) => (
              <li key={c.id} className="border-border grid gap-3 rounded-lg border p-4">
                <div className="flex flex-wrap items-center gap-2">
                  <strong>{c.name}</strong>
                  {c.status === 'new' && <Badge tone="warning">New</Badge>}
                  <span className="text-fg-muted text-sm">{c.at}</span>
                </div>
                <p className="text-sm">{[c.phone, c.email].filter(Boolean).join(' · ')}</p>
                {c.message && <p className="whitespace-pre-line">{c.message}</p>}
                <ol
                  aria-label={`Conversation with ${c.name}`}
                  className="bg-muted grid gap-1 rounded-md p-3 text-sm"
                >
                  {c.transcript.map((l, k) => (
                    <li key={k}>
                      <strong>{l.from === 'visitor' ? c.name : 'Chat'}:</strong> {l.text}
                    </li>
                  ))}
                </ol>
                <div className="flex flex-wrap gap-3 text-sm">
                  {c.contact_id && (
                    <a className="underline" href={`${appBase}/contacts/${c.contact_id}`}>
                      Contact
                    </a>
                  )}
                  {c.lead_id && (
                    <a className="underline" href={`${appBase}/leads/${c.lead_id}`}>
                      Lead
                    </a>
                  )}
                  {c.status === 'new' && (
                    <Button size="sm" variant="ghost" onClick={() => status(c, 'read')}>
                      Mark as read
                    </Button>
                  )}
                  {c.status !== 'archived' ? (
                    <Button size="sm" variant="ghost" onClick={() => status(c, 'archived')}>
                      Archive
                    </Button>
                  ) : (
                    <Button size="sm" variant="ghost" onClick={() => status(c, 'read')}>
                      Restore
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </AppShell>
  );
}

function ConversationItem({
  c,
  base,
  appBase,
}: {
  c: Conversation;
  base: string;
  appBase: string;
}) {
  const [teaching, setTeaching] = useState(false);
  const lastQuestion = [...c.lines].reverse().find((l) => l.from === 'customer')?.text ?? '';
  const teach = useForm({
    keywords: lastQuestion
      .toLowerCase()
      .replace(/[^\p{L}\p{N}\s]+/gu, ' ')
      .split(/\s+/)
      .filter((w) => w.length > 3)
      .slice(0, 6)
      .join(', '),
    answer: '',
  });
  const act = (action: 'take_over' | 'hand_back' | 'close') =>
    router.put(`${base}/ai/${c.id}`, { action }, { preserveScroll: true });
  const who = c.name ?? 'A visitor';
  const outcome = c.outcome ? OUTCOME[c.outcome] : undefined;
  return (
    <li className="border-border grid gap-3 rounded-lg border p-4">
      <div className="flex flex-wrap items-center gap-2">
        <strong>{who}</strong>
        {outcome && <Badge tone={outcome[1]}>{outcome[0]}</Badge>}
        <span className="text-fg-muted text-sm">
          {STATUS[c.status]} · {c.at}
        </span>
      </div>
      {(c.phone || c.email) && (
        <p className="text-sm">{[c.phone, c.email].filter(Boolean).join(' · ')}</p>
      )}
      <ol
        aria-label={`Conversation with ${who}`}
        className="bg-muted grid gap-1 rounded-md p-3 text-sm"
      >
        {c.lines.map((l, k) => (
          <li key={k} className="whitespace-pre-line">
            <strong>
              {l.from === 'customer' ? who : l.from === 'assistant' ? 'Assistant' : 'You'}:
            </strong>{' '}
            {l.text}
            {l.file && 'url' in l.file && (
              <>
                {' '}
                <a className="underline" href={l.file.url}>
                  Download {l.file.kind === 'image' ? 'photo' : l.file.kind}
                  <span className="sr-only">: {l.file.name}</span>
                </a>
              </>
            )}
            {l.file && 'blocked' in l.file && (
              <span className="text-fg-muted">
                {' '}
                ({BLOCKED[l.file.blocked] ?? 'A file not kept'})
              </span>
            )}
            {l.delivery && (
              <span
                className={l.delivery === 'failed' ? 'text-danger-11' : 'text-fg-muted'}
                title={l.error ?? undefined}
              >
                {' '}
                · {DELIVERY[l.delivery]}
                {l.delivery === 'failed' && l.error ? `: ${l.error}` : ''}
              </span>
            )}
          </li>
        ))}
      </ol>
      <div className="flex flex-wrap gap-3 text-sm">
        {c.contact_id && (
          <a className="underline" href={`${appBase}/contacts/${c.contact_id}`}>
            Contact
          </a>
        )}
        {c.channel === 'whatsapp' && <Badge tone="info">WhatsApp</Badge>}
        {c.status === 'ai' || c.status === 'waiting' ? (
          <Button size="sm" variant="secondary" onClick={() => act('take_over')}>
            Take over
          </Button>
        ) : c.status === 'person' ? (
          <Button size="sm" variant="ghost" onClick={() => act('hand_back')}>
            Hand back to the assistant
          </Button>
        ) : null}
        {c.status !== 'closed' && (
          <Button size="sm" variant="ghost" onClick={() => act('close')}>
            Close
          </Button>
        )}
        <Button
          size="sm"
          variant="ghost"
          aria-expanded={teaching}
          onClick={() => setTeaching(!teaching)}
        >
          Teach it
        </Button>
      </div>
      {c.channel === 'whatsapp' && c.status !== 'closed' && <WhatsAppReply c={c} base={base} />}
      {teaching && (
        <form
          aria-label={`Teach the assistant an answer, from the chat with ${who}`}
          className="border-border grid gap-3 rounded-md border p-3"
          onSubmit={(e) => {
            e.preventDefault();
            teach.post(`${base}/ai/${c.id}/teach`, {
              preserveScroll: true,
              onSuccess: () => setTeaching(false),
            });
          }}
        >
          <p className="text-fg-muted text-sm">
            Your answer is added to your chat’s answers (Website → Chat). The assistant uses it from
            now on.
          </p>
          <Field
            label="Words people use when they ask this"
            hint="Separate with commas."
            error={teach.errors.keywords}
          >
            <Input
              value={teach.data.keywords}
              onChange={(e) => teach.setData('keywords', e.target.value)}
            />
          </Field>
          <Field label="Your answer" error={teach.errors.answer}>
            <Textarea
              rows={3}
              maxLength={600}
              value={teach.data.answer}
              onChange={(e) => teach.setData('answer', e.target.value)}
            />
          </Field>
          <Button type="submit" size="sm" className="justify-self-start" loading={teach.processing}>
            Save the answer
          </Button>
        </form>
      )}
    </li>
  );
}

/** Reply on WhatsApp from Chats (21.2): text, a photo or a PDF, inside WhatsApp's 24-hour window. */
function WhatsAppReply({ c, base }: { c: Conversation; base: string }) {
  const form = useForm<{ text: string; file: File | null }>({ text: '', file: null });
  const followUp = usePage<{ follow_up?: boolean }>().props.follow_up === true;
  const nudge = useForm<{ text: string }>({ text: '' });
  if (!c.window_open) {
    return (
      <div className="grid gap-2">
        <p className="text-fg-muted text-sm">
          More than 24 hours since {c.name ?? 'the customer'} last wrote, so WhatsApp only allows an
          approved message.{' '}
          {followUp
            ? 'Send your follow-up: when they reply, you can write freely again.'
            : `Your follow-up message is waiting for Meta’s approval (Settings → WhatsApp)${c.phone ? '. Or call ' + c.phone : ''}.`}
        </p>
        {followUp && (
          <div className="flex flex-wrap items-center gap-3">
            <Button
              size="sm"
              variant="secondary"
              loading={nudge.processing}
              onClick={() => nudge.post(`${base}/ai/${c.id}/follow-up`, { preserveScroll: true })}
            >
              Send follow-up
            </Button>
            {nudge.errors.text && (
              <p role="alert" className="text-danger-11 text-sm">
                {nudge.errors.text}
              </p>
            )}
          </div>
        )}
      </div>
    );
  }
  return (
    <form
      aria-label={`Reply to ${c.name ?? 'the customer'} on WhatsApp`}
      className="grid gap-2"
      onSubmit={(e) => {
        e.preventDefault();
        form.post(`${base}/ai/${c.id}/reply`, {
          preserveScroll: true,
          forceFormData: true,
          onSuccess: () => form.reset(),
        });
      }}
    >
      <Field
        label="Your reply"
        hint={c.window_ends ? `You can reply freely until ${c.window_ends}.` : undefined}
        error={form.errors.text ?? form.errors.file}
      >
        <Textarea
          rows={2}
          maxLength={4096}
          value={form.data.text}
          onChange={(e) => form.setData('text', e.target.value)}
        />
      </Field>
      <div className="flex flex-wrap items-center gap-3">
        <Input
          type="file"
          accept="image/jpeg,image/png,application/pdf"
          aria-label="Attach a photo or PDF"
          className="max-w-xs"
          onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
        />
        <Button type="submit" size="sm" loading={form.processing}>
          Send on WhatsApp
        </Button>
      </div>
    </form>
  );
}
