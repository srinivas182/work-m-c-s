import { Head, Link, router } from '@inertiajs/react';
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
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Data {
  id: string;
  title: string;
  stage: string;
  value_cents: number | null;
  source: string;
  assigned_to: string | null;
  follow_up: string | null;
  closed: boolean;
  contact: { id: string; name: string; phone: string | null; email: string | null } | null;
}
const selectClass = 'border-border bg-bg h-10 w-full rounded-md border px-3 text-sm';
const EVENT: Record<string, string> = {
  created: 'Opened',
  moved: 'Moved to',
  assigned: 'Assigned to',
  follow_up: 'Follow-up',
  note: 'Note',
};
const SOURCE: Record<string, string> = {
  manual: 'Added by hand',
  form: 'Website form',
  chat: 'Chat',
  quote: 'Quote request',
};

export default function Lead({
  lead: l,
  stages,
  team,
  history,
  canManage,
}: {
  lead: Data;
  stages: { id: string; name: string; kind: string }[];
  team: { id: string; name: string }[];
  history: { event: string; detail: string | null; by: string | null; at: string }[];
  canManage: boolean;
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  const leads = base.replace(/\/[^/]+$/, '');
  const contacts = leads.replace(/\/leads$/, '/contacts');
  const [followUp, setFollowUp] = useState(l.follow_up ?? '');
  const [note, setNote] = useState('');
  const post = (path: string, data: Record<string, string | null>) =>
    router.post(`${base}/${path}`, data, { preserveScroll: true });
  return (
    <AppShell title={l.title}>
      <Head title={l.title} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title={l.title}
          description={`${SOURCE[l.source] ?? l.source}${l.value_cents != null ? ` · R${(l.value_cents / 100).toLocaleString('en-ZA', { maximumFractionDigits: 0 })}` : ''}`}
          actions={l.closed ? <Badge tone="neutral">Closed</Badge> : undefined}
        />
        <p>
          <Link href={leads} className="underline">
            ← All leads
          </Link>
        </p>
        {l.contact && (
          <p className="text-sm">
            <Link className="underline" href={`${contacts}/${l.contact.id}`}>
              {l.contact.name}
            </Link>
            {[l.contact.phone, l.contact.email].filter(Boolean).map((x) => ` · ${x}`)}
          </p>
        )}
        {canManage && (
          <Card>
            <CardHeader>
              <CardTitle>Follow up</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-2">
              <Field label="Stage">
                <select
                  className={selectClass}
                  value={l.stage}
                  onChange={(e) => post('move', { stage: e.target.value })}
                >
                  {stages.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Assigned to">
                <select
                  className={selectClass}
                  value={l.assigned_to ?? ''}
                  onChange={(e) => post('assign', { member: e.target.value || null })}
                >
                  <option value="">No one</option>
                  {team.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name}
                    </option>
                  ))}
                </select>
              </Field>
              <form
                aria-label="Follow-up date"
                className="flex flex-wrap items-end gap-2 sm:col-span-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  post('follow-up', { at: followUp || null });
                }}
              >
                <Field label="Follow up on">
                  <input
                    type="datetime-local"
                    className={selectClass}
                    value={followUp}
                    onChange={(e) => setFollowUp(e.target.value)}
                  />
                </Field>
                <Button type="submit" variant="secondary">
                  Save follow-up
                </Button>
              </form>
              <form
                aria-label="Add a note"
                className="grid gap-2 sm:col-span-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  if (note.trim())
                    router.post(
                      `${base}/note`,
                      { text: note },
                      { preserveScroll: true, onSuccess: () => setNote('') },
                    );
                }}
              >
                <Field label="Note">
                  <textarea
                    className="border-border bg-bg min-h-20 rounded-md border p-2 text-sm"
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                  />
                </Field>
                <Button type="submit" variant="secondary" className="justify-self-start">
                  Add note
                </Button>
              </form>
            </CardContent>
          </Card>
        )}
        <Card>
          <CardHeader>
            <CardTitle>History</CardTitle>
          </CardHeader>
          <CardContent>
            <ul aria-label="History" className="grid gap-2 text-sm">
              {history.map((h, k) => (
                <li key={k}>
                  <strong>{EVENT[h.event] ?? h.event}</strong>
                  {h.detail ? ` ${h.detail}` : ''}{' '}
                  <span className="text-fg-muted">
                    — {h.at}
                    {h.by ? `, ${h.by}` : ''}
                  </span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
