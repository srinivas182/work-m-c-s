import { usePage } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';
import { useState } from 'react';
import { Button, Input, Sheet, SheetContent, Tooltip } from '@/design-system';

interface Article {
  slug: string;
  title: string;
  summary: string | null;
  html: string;
  video: string | null;
  poster: string | null;
  url: string;
}

/** "?" on every back-office screen (Sprint 16.1b): this screen's help (articles and 30-second videos), and search. */
export function HelpPanel() {
  const screen = usePage().component;
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [articles, setArticles] = useState<Article[] | null>(null);
  const [home, setHome] = useState('');
  const load = (query: string) => {
    setArticles(null);
    fetch(`/_help?${new URLSearchParams(query ? { q: query } : { screen })}`, {
      headers: { Accept: 'application/json' },
    })
      .then((r) => (r.ok ? r.json() : { articles: [], home: '' }))
      .then((d: { articles: Article[]; home: string }) => {
        setArticles(d.articles);
        setHome(d.home);
      })
      .catch(() => setArticles([]));
  };
  return (
    <>
      <Tooltip content="Help for this page">
        <Button
          variant="ghost"
          size="icon"
          aria-label="Help for this page"
          onClick={() => {
            setQ('');
            setOpen(true);
            load(''); // this screen's help, fetched when asked for
          }}
        >
          <CircleHelp />
        </Button>
      </Tooltip>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="right" title="Help">
          <div className="grid gap-4 p-5 text-sm">
            <form
              role="search"
              onSubmit={(e) => {
                e.preventDefault();
                load(q.trim());
              }}
            >
              <Input
                aria-label="Search help"
                placeholder="Search help"
                value={q}
                onChange={(e) => setQ(e.target.value)}
              />
            </form>
            {articles === null ? (
              <p className="text-fg-muted">Loading…</p>
            ) : articles.length === 0 ? (
              <p className="text-fg-muted">
                {q.trim()
                  ? 'Nothing found. Try other words.'
                  : 'No help for this page yet — try a search.'}
              </p>
            ) : (
              <ul aria-label="Help articles" className="grid gap-2">
                {articles.map((a) => (
                  <li key={a.slug} className="border-border rounded-lg border">
                    <details>
                      <summary className="cursor-pointer p-3 font-semibold">{a.title}</summary>
                      <div className="grid gap-2 px-3 pb-3">
                        {a.video && (
                          <video
                            controls
                            preload="none"
                            playsInline
                            poster={a.poster ?? undefined}
                            aria-label={`Video: ${a.title}`}
                            src={a.video}
                            className="w-full rounded-md"
                          />
                        )}
                        {/* Rendered on the server from Markdown with raw HTML stripped and unsafe links refused. */}
                        <div
                          className="prose prose-sm max-w-none"
                          dangerouslySetInnerHTML={{ __html: a.html }}
                        />
                        <a className="underline" href={a.url} target="_blank" rel="noreferrer">
                          Open in the help centre
                        </a>
                      </div>
                    </details>
                  </li>
                ))}
              </ul>
            )}
            {home && (
              <a className="underline" href={home} target="_blank" rel="noreferrer">
                Browse all help
              </a>
            )}
          </div>
        </SheetContent>
      </Sheet>
    </>
  );
}
