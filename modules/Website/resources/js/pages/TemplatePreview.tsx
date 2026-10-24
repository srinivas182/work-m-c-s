import '../site/fonts';
import { Head } from '@inertiajs/react';
import { SiteRenderer } from '../site/SiteRenderer';
import type { Branding, ContentDocument, MenuItem } from '../site/types';

interface Props {
  template: { key: string; name: string; category: string | null };
  page: { key: string; title: string };
  pages: { key: string; title: string }[];
  content: ContentDocument;
  branding: Branding;
  menus: { header: MenuItem[]; footer: MenuItem[] };
  previewBase: string;
}

export default function TemplatePreview({
  template,
  page,
  pages,
  content,
  branding,
  menus,
  previewBase,
}: Props) {
  return (
    <>
      <Head title={`${template.name} — template preview`} />
      <div className="sticky top-0 z-50 flex flex-wrap items-center gap-3 bg-[#1B1640] px-4 py-2.5 text-sm text-white">
        <span className="font-semibold">{template.name}</span>
        {template.category && <span className="text-white/70">· {template.category}</span>}
        <label className="ml-auto inline-flex items-center gap-2">
          <span className="text-white/70">Page</span>
          <select
            className="rounded-md bg-white/10 px-2 py-1 text-white"
            value={page.key}
            onChange={(e) => (window.location.href = `${previewBase}?page=${e.target.value}`)}
          >
            {pages.map((p) => (
              <option key={p.key} value={p.key} className="text-black">
                {p.title}
              </option>
            ))}
          </select>
        </label>
        <span className="w-full text-xs text-white/70 sm:w-auto">
          Sample content — your own details and branding replace it.
        </span>
      </div>
      <SiteRenderer
        fill
        document={content}
        branding={branding}
        site={{ businessName: 'Your business', editing: false, logo: null, menus }}
      />
    </>
  );
}
