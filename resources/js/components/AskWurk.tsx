import { usePage } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button, Sheet, SheetContent, Textarea, Tooltip } from '@/design-system';

interface Source {
  title: string;
  url: string;
}

interface Action {
  id: string;
  summary: string;
  changes: string[];
  status: 'proposed' | 'applied' | 'undone' | 'cancelled';
  link: string | null;
}

interface Turn {
  id: string;
  question: string;
  answer: string | null;
  sources: Source[];
  action: Action | null;
  note?: string;
}

interface Usage {
  used: number;
  free: number;
  free_left: number;
  cap: number;
  credits_each: number | null;
  balance: number;
}

function csrf(): string {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
  return decodeURIComponent(m?.[1] ?? '');
}

async function call<T>(url: string, body?: unknown): Promise<{ ok: boolean; data: T }> {
  const r = await fetch(url, {
    method: body === undefined ? 'GET' : 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': csrf(),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const data = (await r
    .json()
    .catch(() => ({ message: 'Something went wrong. Please try again.' }))) as T;
  return { ok: r.ok, data };
}

function usageLine(u: Usage): string {
  if (u.free_left > 0) return `${u.free_left} of ${u.free} free questions left this month`;
  if (u.credits_each === null) return 'This month’s free questions are used up';
  return `Each question now uses ${u.credits_each} credit · you have ${u.balance}`;
}

/**
 * "Ask WURK" (Sprint 16.2): ask how to do something, ask about the business's own figures, or ask for a change —
 * which is shown exactly and only made when confirmed (with undo). Loads when opened.
 */
export function AskWurk() {
  const { props, component } = usePage<{
    assistant?: { url: string } | null;
    support?: { url: string } | null;
  }>();
  const base = props.assistant?.url;
  const [open, setOpen] = useState(false);
  const [turns, setTurns] = useState<Turn[]>([]);
  const [usage, setUsage] = useState<Usage | null>(null);
  const [suggestions, setSuggestions] = useState<string[]>([]);
  const [q, setQ] = useState('');
  const [busy, setBusy] = useState(false);
  const [quote, setQuote] = useState<{ question: string; message: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const end = useRef<HTMLDivElement>(null);
  if (!base) return null;

  const scroll = () => setTimeout(() => end.current?.scrollIntoView?.({ block: 'end' }), 0);

  const load = () => {
    void call<{ usage: Usage; history: Turn[]; suggestions: string[] }>(base).then(
      ({ ok, data }) => {
        if (!ok) return;
        setUsage(data.usage);
        setTurns(data.history);
        setSuggestions(data.suggestions);
        scroll();
      },
    );
  };

  const ask = async (question: string, acceptCharge = false) => {
    const text = question.trim();
    if (text.length < 2 || busy) return;
    setBusy(true);
    setError(null);
    setQuote(null);
    const { data } = await call<{
      status: string;
      message?: string;
      id?: string;
      answer?: string;
      sources?: Source[];
      action?: Action | null;
      usage?: Usage;
    }>(`${base}/ask`, { question: text, screen: component, accept_charge: acceptCharge });
    setBusy(false);
    if (data.status === 'quote') {
      setQuote({ question: text, message: data.message ?? '' });
      return;
    }
    if (data.status !== 'answered') {
      setError(data.message ?? 'Something went wrong. Please try again.');
      return;
    }
    setQ('');
    setTurns((t) => [
      ...t,
      {
        id: data.id ?? String(t.length),
        question: text,
        answer: data.answer ?? '',
        sources: data.sources ?? [],
        action: data.action ?? null,
      },
    ]);
    if (data.usage) setUsage(data.usage);
    scroll();
  };

  const act = async (turn: Turn, verb: 'confirm' | 'undo' | 'cancel') => {
    if (!turn.action) return;
    const { ok, data } = await call<{ status: string; message?: string; action?: Action }>(
      `${base}/actions/${turn.action.id}/${verb}`,
      {},
    );
    setTurns((ts) =>
      ts.map((t) =>
        t.id !== turn.id || !t.action
          ? t
          : {
              ...t,
              action: ok
                ? (data.action ?? {
                    ...t.action,
                    status: verb === 'cancel' ? 'cancelled' : t.action.status,
                  })
                : t.action,
              note: data.message,
            },
      ),
    );
  };

  return (
    <>
      <Tooltip content="Ask WURK">
        <Button
          variant="ghost"
          size="icon"
          aria-label="Ask WURK"
          onClick={() => {
            setOpen(true);
            load(); // fetched when asked for, never on every page
          }}
        >
          <Sparkles />
        </Button>
      </Tooltip>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="right" title="Ask WURK">
          <div className="flex h-full flex-col gap-3 p-5 text-sm">
            <p className="text-fg-muted">
              Ask how to do something, ask about your figures, or ask me to make a change — you’ll
              see exactly what changes before anything happens.
            </p>
            <div aria-live="polite" className="grid flex-1 content-start gap-4 overflow-y-auto">
              {turns.length === 0 && suggestions.length > 0 && (
                <ul aria-label="Things you can ask" className="flex flex-wrap gap-2">
                  {suggestions.map((s) => (
                    <li key={s}>
                      <Button variant="secondary" size="sm" onClick={() => void ask(s)}>
                        {s}
                      </Button>
                    </li>
                  ))}
                </ul>
              )}
              {turns.map((t) => (
                <div key={t.id} className="grid gap-2">
                  <p className="bg-neutral-3 justify-self-end rounded-lg px-3 py-2 font-medium">
                    {t.question}
                  </p>
                  {t.answer && <p className="whitespace-pre-line">{t.answer}</p>}
                  {t.sources.length > 0 && (
                    <ul aria-label="From the help centre" className="grid gap-1">
                      {t.sources.map((s) => (
                        <li key={s.url}>
                          <a className="underline" href={s.url} target="_blank" rel="noreferrer">
                            {s.title}
                          </a>
                        </li>
                      ))}
                    </ul>
                  )}
                  {t.action && (
                    <div
                      className="border-border grid gap-2 rounded-lg border p-3"
                      aria-label="Proposed change"
                    >
                      <p className="font-semibold">{t.action.summary}</p>
                      <ul className="list-disc pl-5">
                        {t.action.changes.map((c) => (
                          <li key={c}>{c}</li>
                        ))}
                      </ul>
                      {t.action.status === 'proposed' && (
                        <div className="flex gap-2">
                          <Button size="sm" onClick={() => void act(t, 'confirm')}>
                            Confirm
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => void act(t, 'cancel')}>
                            Cancel
                          </Button>
                        </div>
                      )}
                      {t.action.status === 'applied' && (
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="text-success-11">Done.</span>
                          {t.action.link && (
                            <a className="underline" href={t.action.link}>
                              Open
                            </a>
                          )}
                          <Button size="sm" variant="secondary" onClick={() => void act(t, 'undo')}>
                            Undo
                          </Button>
                        </div>
                      )}
                      {t.action.status === 'undone' && (
                        <span className="text-fg-muted">Undone.</span>
                      )}
                      {t.action.status === 'cancelled' && (
                        <span className="text-fg-muted">Cancelled — nothing changed.</span>
                      )}
                      {t.note && t.action.status === 'proposed' && (
                        <p role="alert" className="text-danger-11">
                          {t.note}
                        </p>
                      )}
                      {t.note && t.action.status === 'applied' && (
                        <p className="text-fg-muted">{t.note}</p>
                      )}
                    </div>
                  )}
                </div>
              ))}
              {busy && <p className="text-fg-muted">Thinking…</p>}
              <div ref={end} />
            </div>
            {quote && (
              <div role="alert" className="border-border grid gap-2 rounded-lg border p-3">
                <p>{quote.message}</p>
                <div className="flex gap-2">
                  <Button size="sm" onClick={() => void ask(quote.question, true)}>
                    Ask anyway
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setQuote(null)}>
                    Not now
                  </Button>
                </div>
              </div>
            )}
            {error && (
              <p role="alert" className="text-danger-11">
                {error}
              </p>
            )}
            {props.support && turns.length > 0 && (
              <a
                className="text-sm underline"
                href={`${props.support.url}?from=${encodeURIComponent(window.location.pathname)}`}
              >
                Not what you needed? Contact support
              </a>
            )}
            <form
              className="grid gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                void ask(q);
              }}
            >
              <Textarea
                aria-label="Your question"
                placeholder="e.g. How many bookings do we have tomorrow?"
                rows={2}
                maxLength={500}
                value={q}
                onChange={(e) => setQ(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    void ask(q);
                  }
                }}
              />
              <div className="flex items-center justify-between gap-2">
                <span className="text-fg-muted text-xs">{usage ? usageLine(usage) : ''}</span>
                <Button type="submit" size="sm" loading={busy}>
                  Ask
                </Button>
              </div>
            </form>
          </div>
        </SheetContent>
      </Sheet>
    </>
  );
}
