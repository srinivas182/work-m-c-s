import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Checkbox,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Answer {
  id?: string;
  kind: 'button' | 'keyword';
  label: string | null;
  keywords: string | null;
  answer: string;
  link_kind: 'none' | 'page' | 'call' | 'whatsapp' | 'map' | 'email';
  link_target: string | null;
  fact?: string | null;
  edited?: boolean;
  reset?: boolean;
}
interface Props {
  settings: {
    enabled: boolean;
    greeting: string;
    fallback: string;
    hidden: string[];
    leads: boolean;
  };
  answers: Answer[];
  pages: { path: string; title: string }[];
  preview: { greeting: string; buttons: { label: string }[]; reply: string };
  taps: { label: string; taps: number }[];
}

const LINKS: [Answer['link_kind'], string][] = [
  ['none', 'No link'],
  ['page', 'A page'],
  ['call', 'Call us'],
  ['whatsapp', 'WhatsApp'],
  ['map', 'Map'],
  ['email', 'Email'],
];
const select = 'border-border bg-bg h-10 w-full rounded-md border px-3 text-sm';

export default function Chat({ settings, answers, pages, preview, taps }: Props) {
  const s = useForm(settings);
  const [rows, setRows] = useState<Answer[]>(answers);
  const [error, setError] = useState<string | null>(null);
  const base = window.location.pathname.replace(/\/$/, '');
  const set = (i: number, patch: Partial<Answer>) =>
    setRows(rows.map((r, k) => (k === i ? { ...r, ...patch } : r)));
  const move = (i: number, by: number) => {
    const next = [...rows];
    const [r] = next.splice(i, 1);
    if (r) next.splice(i + by, 0, r);
    setRows(next);
  };
  return (
    <AppShell title="Chat">
      <Head title="Chat" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Chat"
          description="A chat on your website that answers common questions straight away and, when someone wants a person, takes their details for you. Only chats that reach you are kept."
        />
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              Settings{' '}
              {settings.enabled ? (
                <Badge tone="success">On</Badge>
              ) : (
                <Badge tone="neutral">Off</Badge>
              )}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <form
              aria-label="Chat settings"
              className="grid gap-4"
              onSubmit={(e) => {
                e.preventDefault();
                s.put(`${base}/settings`, { preserveScroll: true });
              }}
            >
              <label className="flex items-center gap-2 text-sm">
                <Checkbox
                  checked={s.data.enabled}
                  onCheckedChange={(on) => s.setData('enabled', on === true)}
                />
                Show the chat on my website
              </label>
              <Field label="Greeting" error={s.errors.greeting}>
                <Input
                  value={s.data.greeting}
                  onChange={(e) => s.setData('greeting', e.target.value)}
                />
              </Field>
              <Field label="When there’s no answer" error={s.errors.fallback}>
                <Input
                  value={s.data.fallback}
                  onChange={(e) => s.setData('fallback', e.target.value)}
                />
              </Field>
              <label className="flex items-center gap-2 text-sm">
                <Checkbox
                  checked={s.data.leads}
                  onCheckedChange={(on) => s.setData('leads', on === true)}
                />
                Open a lead when someone asks for a person
              </label>
              <fieldset className="grid gap-2">
                <legend className="text-sm font-medium">Hide the chat on</legend>
                {pages.map((p) => (
                  <label key={p.path} className="flex items-center gap-2 text-sm">
                    <Checkbox
                      checked={s.data.hidden.includes(p.path)}
                      onCheckedChange={(on) =>
                        s.setData(
                          'hidden',
                          on === true
                            ? [...s.data.hidden, p.path]
                            : s.data.hidden.filter((x) => x !== p.path),
                        )
                      }
                    />
                    {p.title}
                  </label>
                ))}
              </fieldset>
              <Button type="submit" className="justify-self-start" loading={s.processing}>
                Save settings
              </Button>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Answers</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4">
            {error && (
              <p role="alert" className="text-danger text-sm">
                {error}
              </p>
            )}
            <ol aria-label="Answers" className="grid gap-4">
              {rows.map((r, i) => (
                <li
                  key={r.id ?? `new-${i}`}
                  className="border-border grid gap-3 rounded-lg border p-3"
                >
                  <div className="flex flex-wrap items-center gap-2 text-sm">
                    <strong>
                      {r.kind === 'button' ? r.label || 'New button' : 'Keyword answer'}
                    </strong>
                    {r.fact && !r.edited && !r.reset && (
                      <Badge tone="neutral">Ready-made — follows your details</Badge>
                    )}
                    {r.fact && r.edited && !r.reset && (
                      <Button size="sm" variant="ghost" onClick={() => set(i, { reset: true })}>
                        Back to ready-made
                      </Button>
                    )}
                  </div>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Type">
                      <select
                        className={select}
                        value={r.kind}
                        onChange={(e) => set(i, { kind: e.target.value as Answer['kind'] })}
                      >
                        <option value="button">A button</option>
                        <option value="keyword">Keywords only</option>
                      </select>
                    </Field>
                    {r.kind === 'button' && (
                      <Field label="Button text">
                        <Input
                          maxLength={30}
                          value={r.label ?? ''}
                          onChange={(e) => set(i, { label: e.target.value })}
                        />
                      </Field>
                    )}
                    <Field label="Words that lead here (separated by commas)">
                      <Input
                        value={r.keywords ?? ''}
                        onChange={(e) => set(i, { keywords: e.target.value })}
                      />
                    </Field>
                    <Field label="Link">
                      <select
                        className={select}
                        value={r.link_kind}
                        onChange={(e) =>
                          set(i, { link_kind: e.target.value as Answer['link_kind'] })
                        }
                      >
                        {LINKS.map(([v, t]) => (
                          <option key={v} value={v}>
                            {t}
                          </option>
                        ))}
                      </select>
                    </Field>
                    {r.link_kind === 'page' && (
                      <Field label="Page">
                        <select
                          className={select}
                          value={r.link_target ?? ''}
                          onChange={(e) => set(i, { link_target: e.target.value })}
                        >
                          <option value="">Choose a page…</option>
                          {pages.map((p) => (
                            <option key={p.path} value={p.path}>
                              {p.title}
                            </option>
                          ))}
                        </select>
                      </Field>
                    )}
                  </div>
                  <Field label="Answer">
                    <textarea
                      className="border-border bg-bg min-h-20 rounded-md border p-2 text-sm"
                      maxLength={600}
                      value={r.answer}
                      onChange={(e) => set(i, { answer: e.target.value, reset: false })}
                    />
                  </Field>
                  <div className="flex flex-wrap gap-2">
                    {i > 0 && (
                      <Button
                        size="sm"
                        variant="ghost"
                        aria-label={`Move ${r.label || 'answer'} up`}
                        onClick={() => move(i, -1)}
                      >
                        Up
                      </Button>
                    )}
                    {i < rows.length - 1 && (
                      <Button
                        size="sm"
                        variant="ghost"
                        aria-label={`Move ${r.label || 'answer'} down`}
                        onClick={() => move(i, 1)}
                      >
                        Down
                      </Button>
                    )}
                    <Button
                      size="sm"
                      variant="ghost"
                      aria-label={`Remove ${r.label || 'answer'}`}
                      onClick={() => setRows(rows.filter((_, k) => k !== i))}
                    >
                      Remove
                    </Button>
                  </div>
                </li>
              ))}
            </ol>
            <div className="flex flex-wrap gap-2">
              <Button
                variant="secondary"
                onClick={() =>
                  setRows([
                    ...rows,
                    {
                      kind: 'button',
                      label: '',
                      keywords: '',
                      answer: '',
                      link_kind: 'none',
                      link_target: null,
                    },
                  ])
                }
              >
                Add an answer
              </Button>
              <Button
                onClick={() =>
                  router.put(
                    `${base}/answers`,
                    { answers: rows.map((r) => ({ ...r })) },
                    {
                      preserveScroll: true,
                      onError: (e) => setError(Object.values(e)[0] ?? null),
                      onSuccess: () => setError(null),
                    },
                  )
                }
              >
                Save answers
              </Button>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Preview</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-2 text-sm">
            <p>{preview.greeting}</p>
            <p className="flex flex-wrap gap-2">
              {preview.buttons.map((b) => (
                <span key={b.label} className="border-border rounded-full border px-3 py-1">
                  {b.label}
                </span>
              ))}
            </p>
            <p className="text-fg-muted">When someone asks for a person: “{preview.reply}”</p>
          </CardContent>
        </Card>

        {taps.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Most used in the last 30 days</CardTitle>
            </CardHeader>
            <CardContent>
              <ul aria-label="Most used buttons" className="grid gap-1 text-sm">
                {taps.map((t) => (
                  <li key={t.label}>
                    {t.label}: {t.taps}
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
