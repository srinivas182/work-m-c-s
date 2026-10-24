import '../site/fonts';
// Puck's default stylesheet imports Inter from rsms.me (a third-party request from
// every owner's browser). This variant has no external imports; Inter is self-hosted.
import '@puckeditor/core/no-external.css';
import '../site/site.css';
import { Head } from '@inertiajs/react';
import { Puck, type Data } from '@puckeditor/core';
import { ArrowLeft, Check, CloudOff, History, Loader2, Rocket, TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Button, Dialog, DialogContent, DialogFooter, Field, Input } from '@/design-system';
import { buildConfig, fromPuck, toPuck } from '../editor/adapter';
import { firstError, request } from '../editor/http';
import { siteTheme } from '../site/theme';
import type { Branding, ContentDocument, MenuItem } from '../site/types';

interface Props {
  page: { id: string; title: string; slug: string; is_home: boolean };
  revision: number;
  content: ContentDocument;
  published: boolean;
  canPublish: boolean;
  businessName: string;
  branding: Branding;
  menus: { header: MenuItem[]; footer: MenuItem[] };
  urls: {
    pages: string;
    draft: string;
    publish: string;
    versions: string;
    photos: string;
    aiImage?: string;
    aiQuote?: string;
  };
}

type Status = 'saved' | 'unsaved' | 'saving' | 'conflict' | 'error';
type Version = {
  id: string;
  number: number;
  note: string | null;
  published_at: string;
  live: boolean;
};

const AUTOSAVE_MS = 1200;

const STATUS: Record<Status, { icon: ReactNode; text: string; tone: string }> = {
  saved: {
    icon: <Check className="size-4" aria-hidden />,
    text: 'All changes saved',
    tone: 'text-[#2f6b3a]',
  },
  unsaved: { icon: null, text: 'Unsaved changes', tone: 'text-[#5b5776]' },
  saving: {
    icon: <Loader2 className="size-4 animate-spin" aria-hidden />,
    text: 'Saving…',
    tone: 'text-[#5b5776]',
  },
  conflict: {
    icon: <TriangleAlert className="size-4" aria-hidden />,
    text: 'Changed by someone else',
    tone: 'text-[#8a1c1c]',
  },
  error: {
    icon: <CloudOff className="size-4" aria-hidden />,
    text: 'Couldn’t save',
    tone: 'text-[#8a1c1c]',
  },
};

/**
 * Puck's list fields render an icon-only "add" button with no accessible name (screen readers
 * hear just "button"). Puck doesn't let us label it, so name any such button as it appears.
 * Remove if Puck adds labels upstream.
 */
function useNameListAddButtons() {
  useEffect(() => {
    const name = () =>
      document
        .querySelectorAll<HTMLButtonElement>(
          'button[class*="ArrayField-addButton"]:not([aria-label])',
        )
        .forEach((b) => {
          if (!b.textContent?.trim()) {
            b.setAttribute('aria-label', 'Add an item');
            b.title = 'Add an item';
          }
        });
    name();
    const observer = new MutationObserver(name);
    observer.observe(document.body, { childList: true, subtree: true });
    return () => observer.disconnect();
  }, []);
}

export default function Editor({
  page,
  revision,
  content,
  published,
  canPublish,
  businessName,
  branding,
  menus,
  urls,
}: Props) {
  useNameListAddButtons();
  const [status, setStatus] = useState<Status>('saved');
  const [message, setMessage] = useState<string | null>(null);
  const [isPublished, setPublished] = useState(published);
  const [publishing, setPublishing] = useState(false);
  const [versions, setVersions] = useState<Version[] | null>(null);
  const [note, setNote] = useState('');

  const revisionRef = useRef(revision);
  const latest = useRef<Data | null>(null);
  const timer = useRef<number | undefined>(undefined);
  const inFlight = useRef<Promise<boolean> | null>(null);
  const dirty = useRef(false);
  const statusRef = useRef<Status>('saved');
  // Lets a save schedule the next one for edits made while it was in flight.
  const saveRef = useRef<(() => Promise<boolean>) | null>(null);
  const setBoth = (s: Status) => {
    statusRef.current = s;
    setStatus(s);
  };

  const config = useMemo(() => {
    const base = buildConfig({
      site: { businessName, editing: true, logo: branding.logo, menus },
      photosUrl: urls.photos,
      ai:
        urls.aiImage && urls.aiQuote
          ? {
              url: urls.aiImage,
              quoteUrl: urls.aiQuote,
              tenantBase: urls.pages.replace(/\/website\/pages$/, ''),
            }
          : undefined,
    });
    // The canvas wears the site's own theme, so editing looks exactly like the published site.
    return {
      ...base,
      root: {
        // Puck shows a "title" field by default; page titles are managed in Pages settings.
        fields: {},
        render: ({ children }: { children: ReactNode }) => (
          <div
            className="wurk-site min-h-full"
            style={{
              ...siteTheme(branding),
              background: 'var(--s-bg)',
              color: 'var(--s-fg)',
              fontFamily: 'var(--s-font-body)',
            }}
          >
            {children}
          </div>
        ),
      },
    };
  }, [businessName, branding, menus, urls.photos, urls.aiImage, urls.aiQuote, urls.pages]);

  const initialData = useMemo(() => toPuck(content), [content]);
  // Puck doesn't record the page as loaded in its history, so the first edit after
  // opening the editor could never be undone. Seed the history with it.
  const initialHistory = useMemo(
    () => ({
      histories: [{ state: { data: initialData } }] as [{ state: { data: Data } }],
      index: 0,
    }),
    [initialData],
  );

  /** Saves the latest data. Never overwrites someone else's save (the server answers 409). */
  const save = useCallback(async (): Promise<boolean> => {
    if (inFlight.current) await inFlight.current;
    if (!dirty.current || !latest.current) return statusRef.current === 'saved';
    if (statusRef.current === 'conflict') return false;

    dirty.current = false;
    setBoth('saving');
    const run = (async () => {
      try {
        const { status: code, data } = await request<{ revision?: number; message?: string }>(
          'PUT',
          urls.draft,
          {
            revision: revisionRef.current,
            content: fromPuck(latest.current as Data),
          },
        );
        if (code === 200 && data.revision) {
          revisionRef.current = data.revision;
          setMessage(null);
          setBoth(dirty.current ? 'unsaved' : 'saved');
          return true;
        }
        if (code === 409) {
          setBoth('conflict');
          setMessage(
            'Someone else saved this page while you were editing. Load their version to carry on — your recent changes can’t be saved over theirs.',
          );
          return false;
        }
        dirty.current = true;
        setBoth('error');
        setMessage(
          firstError(data) ??
            'Your changes couldn’t be saved. Check your connection and try again.',
        );
        return false;
      } catch {
        dirty.current = true;
        setBoth('error');
        setMessage(
          'You seem to be offline. Your changes are kept here — try saving again when you’re back online.',
        );
        return false;
      }
    })();
    inFlight.current = run;
    const ok = await run;
    inFlight.current = null;
    if (ok && dirty.current)
      timer.current = window.setTimeout(() => void saveRef.current?.(), AUTOSAVE_MS);
    return ok;
  }, [urls.draft]);

  useEffect(() => {
    saveRef.current = save;
  }, [save]);

  const onChange = useCallback(
    (data: Data) => {
      latest.current = data;
      dirty.current = true;
      if (statusRef.current === 'conflict') return;
      setBoth('unsaved');
      window.clearTimeout(timer.current);
      timer.current = window.setTimeout(() => void save(), AUTOSAVE_MS);
    },
    [save],
  );

  // Warn before leaving with changes that aren't saved yet.
  useEffect(() => {
    const warn = (e: BeforeUnloadEvent) => {
      if (['unsaved', 'saving', 'error'].includes(statusRef.current)) e.preventDefault();
    };
    window.addEventListener('beforeunload', warn);
    return () => {
      window.removeEventListener('beforeunload', warn);
      window.clearTimeout(timer.current);
    };
  }, []);

  const publish = async () => {
    window.clearTimeout(timer.current);
    if (!(await save()) && statusRef.current !== 'saved') return;
    const { status: code, data } = await request<{ version?: number }>('POST', urls.publish, {
      note: note || null,
    });
    if (code === 200 && data.version) {
      setPublished(true);
      setPublishing(false);
      setNote('');
      setMessage(`Published — version ${data.version} is live.`);
    } else {
      setMessage(firstError(data) ?? 'Publishing didn’t work. Try again.');
    }
  };

  const openVersions = async () => {
    const { data } = await request<Version[]>('GET', urls.versions);
    setVersions(Array.isArray(data) ? data : []);
  };

  const restore = async (v: Version) => {
    const { status: code } = await request('POST', `${urls.versions}/${v.id}/restore`);
    if (code === 200) window.location.reload(); // load the restored draft
  };

  const s = STATUS[status];

  return (
    <>
      <Head title={`Edit ${page.title}`} />
      <Puck
        config={config}
        data={initialData}
        initialHistory={initialHistory}
        onChange={onChange}
        // Puck's own labels, in our voice (its defaults are lowercase).
        dictionary={{ 'header-undo': 'Undo', 'header-redo': 'Redo' }}
        headerTitle={page.title}
        headerPath={page.is_home ? '/' : `/${page.slug}`}
        viewports={[
          { width: 390, height: 'auto', label: 'Phone', icon: 'Smartphone' },
          { width: 820, height: 'auto', label: 'Tablet', icon: 'Tablet' },
          { width: 1280, height: 'auto', label: 'Desktop', icon: 'Monitor' },
        ]}
        renderHeaderActions={() => (
          <div className="flex items-center gap-2">
            <a
              href={urls.pages}
              className="inline-flex items-center gap-1 rounded-md px-2 py-1.5 text-sm font-medium text-[#5b5776] hover:bg-[#f2f0f9]"
            >
              <ArrowLeft className="size-4" aria-hidden /> Pages
            </a>
            <span
              role="status"
              aria-live="polite"
              className={`hidden items-center gap-1.5 px-2 text-sm font-medium md:inline-flex ${s.tone}`}
            >
              {s.icon}
              {s.text}
            </span>
            {status === 'error' && (
              <Button size="sm" variant="secondary" onClick={() => void save()}>
                Try again
              </Button>
            )}
            {status === 'conflict' && (
              <Button size="sm" variant="secondary" onClick={() => window.location.reload()}>
                Load their version
              </Button>
            )}
            <Button
              size="sm"
              variant="ghost"
              className="hidden md:inline-flex"
              onClick={() => void openVersions()}
            >
              <History /> Versions
            </Button>
            {canPublish && (
              <Button
                size="sm"
                className="hidden md:inline-flex"
                disabled={status === 'conflict'}
                onClick={() => setPublishing(true)}
              >
                <Rocket /> {isPublished ? 'Publish changes' : 'Publish'}
              </Button>
            )}
          </div>
        )}
      />

      {/* Phones: Puck folds its header into a menu that its canvas then covers, so the
          save status, Versions and Publish live in this bar instead — within thumb reach. */}
      <div className="fixed bottom-[5.25rem] left-1/2 z-[60] flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-white/95 p-1.5 pl-3 shadow-md ring-1 ring-black/5 md:hidden">
        <span
          role="status"
          aria-live="polite"
          className={`inline-flex items-center gap-1.5 text-xs font-medium whitespace-nowrap ${s.tone}`}
        >
          {s.icon}
          {s.text}
        </span>
        <Button size="sm" variant="ghost" aria-label="Versions" onClick={() => void openVersions()}>
          <History />
        </Button>
        {canPublish && (
          <Button size="sm" disabled={status === 'conflict'} onClick={() => setPublishing(true)}>
            {isPublished ? 'Publish changes' : 'Publish'}
          </Button>
        )}
      </div>

      {message && (
        <div
          role="alert"
          className="fixed inset-x-0 bottom-4 z-[60] mx-auto w-fit max-w-[92vw] rounded-xl bg-[#1B1640] px-4 py-3 text-sm text-white shadow-lg"
        >
          {message}{' '}
          <button
            type="button"
            className="ml-2 font-semibold underline"
            onClick={() => setMessage(null)}
          >
            Dismiss
          </button>
        </div>
      )}

      {publishing && (
        <Dialog open onOpenChange={(o) => !o && setPublishing(false)}>
          <DialogContent
            title={`Publish ${page.title}`}
            description="Visitors see this version as soon as your website is live. You can go back to any earlier version."
          >
            <form
              className="grid gap-4"
              onSubmit={(e) => {
                e.preventDefault();
                void publish();
              }}
            >
              <Field label="What changed? (optional)" hint="Helps you find this version later">
                <Input
                  value={note}
                  maxLength={255}
                  onChange={(e) => setNote(e.target.value)}
                  placeholder="e.g. December prices"
                />
              </Field>
              <DialogFooter>
                <Button type="submit">Publish now</Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      )}

      {versions && (
        <Dialog open onOpenChange={(o) => !o && setVersions(null)}>
          <DialogContent
            title="Published versions"
            description="Restoring copies a version into your draft. Publish it when you’re happy."
          >
            {versions.length === 0 ? (
              <p className="text-fg-muted text-sm">Nothing published yet.</p>
            ) : (
              <ul className="grid max-h-[50vh] gap-2 overflow-y-auto">
                {versions.map((v) => (
                  <li
                    key={v.id}
                    className="border-border flex items-center justify-between gap-3 rounded-lg border p-3"
                  >
                    <span className="text-sm">
                      <span className="font-semibold">Version {v.number}</span>
                      {v.live && (
                        <span className="bg-success-3 text-success-11 ml-2 rounded-full px-2 py-0.5 text-xs font-medium">
                          Live
                        </span>
                      )}
                      <span className="text-fg-muted block">
                        {new Date(v.published_at).toLocaleString()}
                        {v.note ? ` · ${v.note}` : ''}
                      </span>
                    </span>
                    {canPublish && (
                      <Button size="sm" variant="secondary" onClick={() => void restore(v)}>
                        Restore
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </DialogContent>
        </Dialog>
      )}
    </>
  );
}
