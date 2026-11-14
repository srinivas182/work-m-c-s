import { Badge } from '@/design-system';

export interface ThreadMessage {
  id: string;
  from: string;
  staff: boolean;
  internal?: boolean;
  body: string;
  at: string | null;
  files: { id: string; name: string }[];
}

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/** A ticket's conversation, oldest first (Sprint 16.3). Text is shown as text — never as HTML. */
export function Thread({
  messages,
  fileUrl,
}: {
  messages: ThreadMessage[];
  fileUrl: (id: string) => string;
}) {
  return (
    <ol aria-label="Conversation" className="grid gap-3">
      {messages.map((m) => (
        <li
          key={m.id}
          className={`border-border grid gap-2 rounded-lg border p-4 text-sm ${m.internal ? 'bg-warning-2' : m.staff ? 'bg-brand-2' : 'bg-surface'}`}
        >
          <div className="flex flex-wrap items-center gap-2">
            <strong>{m.from}</strong>
            {m.internal && <Badge tone="warning">Internal note</Badge>}
            {m.at && <span className="text-fg-muted">{when.format(new Date(m.at))}</span>}
          </div>
          <p className="whitespace-pre-line">{m.body}</p>
          {m.files.length > 0 && (
            <ul aria-label="Screenshots" className="flex flex-wrap gap-2">
              {m.files.map((f) => (
                <li key={f.id}>
                  <a className="underline" href={fileUrl(f.id)}>
                    {f.name}
                  </a>
                </li>
              ))}
            </ul>
          )}
        </li>
      ))}
    </ol>
  );
}

export const statusLabel: Record<string, string> = {
  open: 'Waiting on WURK',
  pending: 'Waiting on you',
  solved: 'Solved',
};

export function StatusBadge({
  status,
  labels = statusLabel,
}: {
  status: string;
  labels?: Record<string, string>;
}) {
  return (
    <Badge tone={status === 'solved' ? 'success' : status === 'pending' ? 'warning' : 'info'}>
      {labels[status] ?? status}
    </Badge>
  );
}
