import '../site/fonts';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, PencilLine } from 'lucide-react';
import { SiteRenderer } from '../site/SiteRenderer';
import type { Branding, ContentDocument, MenuItem } from '../site/types';

interface Props {
  mode: 'draft' | 'published';
  page: { title: string; slug: string; is_home: boolean; published: boolean };
  content: ContentDocument | null;
  branding: Branding;
  businessName: string;
  menus: { header: MenuItem[]; footer: MenuItem[] };
  pages: { title: string; slug: string; is_home: boolean }[];
  urls: { preview: string; pages: string; editor: string };
}

export default function Preview({
  mode,
  page,
  content,
  branding,
  businessName,
  menus,
  pages,
  urls,
}: Props) {
  const to = (slug: string, m: Props['mode']) =>
    `${urls.preview}?${new URLSearchParams({ page: slug, ...(m === 'published' ? { mode: 'published' } : {}) })}`;
  return (
    <>
      <Head title={`Preview — ${page.title}`} />
      {/* Back-office bar: clearly not part of the site. */}
      <div className="sticky top-0 z-50 flex flex-wrap items-center gap-3 bg-[#1B1640] px-4 py-2.5 text-sm text-white">
        <Link
          href={urls.pages}
          className="inline-flex items-center gap-1 font-medium text-white/80 hover:text-white"
        >
          <ArrowLeft className="size-4" aria-hidden /> Pages
        </Link>
        <label className="inline-flex items-center gap-2">
          <span className="text-white/70">Page</span>
          <select
            className="rounded-md bg-white/10 px-2 py-1 text-white"
            value={page.slug}
            onChange={(e) => (window.location.href = to(e.target.value, mode))}
          >
            {pages.map((p) => (
              <option key={p.slug} value={p.slug} className="text-black">
                {p.title}
              </option>
            ))}
          </select>
        </label>
        <div role="group" aria-label="Version" className="inline-flex rounded-md bg-white/10 p-0.5">
          {(['draft', 'published'] as const).map((m) => (
            <a
              key={m}
              href={to(page.slug, m)}
              aria-current={mode === m ? 'page' : undefined}
              className={`rounded px-2.5 py-1 font-medium ${mode === m ? 'bg-white text-[#1B1640]' : 'text-white/80 hover:text-white'}`}
            >
              {m === 'draft' ? 'Latest changes' : 'Published'}
            </a>
          ))}
        </div>
        <Link
          href={urls.editor}
          className="ml-auto inline-flex items-center gap-1.5 rounded-md bg-white px-3 py-1.5 font-semibold text-[#1B1640]"
        >
          <PencilLine className="size-4" aria-hidden /> Edit this page
        </Link>
      </div>
      {content ? (
        <SiteRenderer
          fill
          document={content}
          branding={branding}
          site={{ businessName, editing: false, logo: branding.logo, menus }}
        />
      ) : (
        <main className="grid min-h-[60vh] place-items-center p-8 text-center">
          <div>
            <p className="text-lg font-semibold">“{page.title}” hasn’t been published yet.</p>
            <p className="text-fg-muted mt-2">
              Visitors won’t see it until you publish it from the editor.
            </p>
            <a
              href={to(page.slug, 'draft')}
              className="text-primary mt-4 inline-block font-semibold underline-offset-4 hover:underline"
            >
              See the latest changes
            </a>
          </div>
        </main>
      )}
    </>
  );
}
