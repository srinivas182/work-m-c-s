import { X } from 'lucide-react';
import { useState } from 'react';
import type { BlockDefinition, SiteContext } from '../types';
import { formatDay, isCurrent } from './dates';

export interface PromotionBannerProps extends Record<string, unknown> {
  text: string;
  linkLabel: string;
  linkHref: string;
  tone: 'brand' | 'accent' | 'dark';
  starts: string;
  ends: string;
}

const TONES = {
  brand: 'bg-[var(--s-brand)] text-[var(--s-on-brand)]',
  accent: 'bg-[var(--s-accent)] text-[var(--s-on-accent)]',
  dark: 'bg-[var(--s-fg)] text-[var(--s-bg)]',
} as const;

function Banner({ p, site }: { p: PromotionBannerProps; site: SiteContext }) {
  const [closed, setClosed] = useState(false);
  const current = isCurrent(p.starts, p.ends);
  if (closed || (!current && !site.editing)) return null; // outside its dates it simply isn't shown
  return (
    <aside
      aria-label="Promotion"
      data-island="promotion"
      className={`px-5 py-3 sm:px-8 ${TONES[p.tone] ?? TONES.brand}`}
    >
      <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-center gap-x-4 gap-y-1 text-center text-sm font-medium">
        <span>{p.text}</span>
        {p.linkLabel && (
          <a
            href={site.editing ? undefined : p.linkHref}
            className="font-semibold underline underline-offset-4"
          >
            {p.linkLabel}
          </a>
        )}
        {site.editing && !current && (
          <span className="rounded-full bg-black/25 px-2 py-0.5 text-xs">
            Hidden on your site — shows {p.starts ? `from ${formatDay(p.starts)}` : ''}{' '}
            {p.ends ? `until ${formatDay(p.ends)}` : ''}
          </span>
        )}
        {!site.editing && (
          <button
            type="button"
            onClick={() => setClosed(true)}
            data-promo-close
            aria-label="Close promotion"
            className="grid size-8 place-items-center rounded-full hover:bg-black/15"
          >
            <X className="size-4" aria-hidden />
          </button>
        )}
      </div>
    </aside>
  );
}

export const PromotionBanner: BlockDefinition<PromotionBannerProps> = {
  type: 'PromotionBanner',
  version: 1,
  label: 'Promotion banner',
  description: 'A short offer across the page, shown only between your dates.',
  fields: {
    text: { kind: 'text', inline: true, label: 'Offer', max: 120 },
    linkLabel: { kind: 'text', inline: true, label: 'Link text', max: 30 },
    linkHref: { kind: 'link', label: 'Link goes to' },
    tone: {
      kind: 'select',
      label: 'Colour',
      options: [
        { value: 'brand', label: 'Main colour' },
        { value: 'accent', label: 'Second colour' },
        { value: 'dark', label: 'Dark' },
      ],
    },
    starts: { kind: 'text', label: 'Show from (YYYY-MM-DD, optional)', max: 10 },
    ends: { kind: 'text', label: 'Show until (YYYY-MM-DD, optional)', max: 10 },
  },
  defaults: {
    text: 'December special: 15% off all treatments',
    linkLabel: 'Book now',
    linkHref: '#book',
    tone: 'brand',
    starts: '',
    ends: '',
  },
  render: (p, site) => <Banner p={p} site={site} />,
};
