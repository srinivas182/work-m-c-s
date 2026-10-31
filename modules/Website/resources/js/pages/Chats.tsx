import { Head, Link, router } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { Badge, Button, EmptyState, PageHeader } from '@/design-system';
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

export default function Chats({ chats, show }: { chats: ChatRow[]; show: 'open' | 'archived' }) {
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
        <p className="text-sm">
          <Link className="underline" href={show === 'open' ? `${base}?show=archived` : base}>
            {show === 'open' ? 'Show archived' : 'Show current'}
          </Link>
        </p>
        {chats.length === 0 ? (
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
