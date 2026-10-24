import { useId, useState } from 'react';
import type { BlockDefinition } from '../types';
import { EmptyHint, Heading, PhotoPrompt, photoSrcSet, Section } from './shared';

export interface BeforeAfterItem {
  before: string;
  beforeAlt: string;
  after: string;
  afterAlt: string;
  caption: string;
}

export interface BeforeAfterProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  layout: 'slider' | 'side-by-side';
  items: BeforeAfterItem[];
}

const img = (src: string, alt: string, className: string) => (
  <img
    src={src}
    srcSet={photoSrcSet(src)}
    sizes="(min-width: 768px) 45vw, 100vw"
    alt={alt}
    loading="lazy"
    decoding="async"
    className={`object-cover ${className}`}
  />
);

/** Keyboard- and screen-reader-friendly comparison: a native range control, not a drag-only handle. */
function Slider({ item }: { item: BeforeAfterItem }) {
  const [split, setSplit] = useState(50);
  const id = useId();
  return (
    <div className="relative aspect-[4/3] overflow-hidden rounded-[var(--s-radius)]">
      {img(item.after, item.afterAlt, 'absolute inset-0 h-full w-full')}
      <div className="absolute inset-0" style={{ clipPath: `inset(0 ${100 - split}% 0 0)` }}>
        {img(item.before, item.beforeAlt, 'h-full w-full')}
      </div>
      <div
        aria-hidden
        className="pointer-events-none absolute inset-y-0 w-0.5 bg-white shadow"
        style={{ left: `${split}%` }}
      />
      <span
        aria-hidden
        className="absolute top-3 left-3 rounded-full bg-black/60 px-2.5 py-1 text-xs font-semibold text-white"
      >
        Before
      </span>
      <span
        aria-hidden
        className="absolute top-3 right-3 rounded-full bg-black/60 px-2.5 py-1 text-xs font-semibold text-white"
      >
        After
      </span>
      <label htmlFor={id} className="sr-only">
        Compare before and after
      </label>
      <input
        id={id}
        type="range"
        min={0}
        max={100}
        value={split}
        onChange={(e) => setSplit(Number(e.target.value))}
        className="absolute inset-x-4 bottom-4 accent-[var(--s-brand)]"
      />
    </div>
  );
}

export const BeforeAfter: BlockDefinition<BeforeAfterProps> = {
  type: 'BeforeAfter',
  version: 1,
  label: 'Before & after',
  description: 'Show the difference you make — real results only.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', multiline: true, max: 240 },
    layout: {
      kind: 'select',
      label: 'Layout',
      options: [
        { value: 'slider', label: 'Slider' },
        { value: 'side-by-side', label: 'Side by side' },
      ],
    },
    items: {
      kind: 'list',
      label: 'Results',
      max: 6,
      item: {
        before: { kind: 'image', label: 'Before photo' },
        beforeAlt: { kind: 'text', label: 'Describe the before photo', max: 140 },
        after: { kind: 'image', label: 'After photo' },
        afterAlt: { kind: 'text', label: 'Describe the after photo', max: 140 },
        caption: { kind: 'text', inline: true, label: 'Caption', max: 120 },
      },
    },
  },
  defaults: { heading: 'Before & after', intro: '', layout: 'slider', items: [] },
  render: (p, site) => {
    // Live site: only complete pairs. Editor: every result, with prompts for missing
    // photos, so the caption is editable before the photos are uploaded.
    const shown = site.editing ? p.items : p.items.filter((i) => i.before && i.after);
    const photo = (src: string, alt: string, className: string, label: string) =>
      src ? img(src, alt, className) : <PhotoPrompt editing className={className} text={label} />;
    return (
      <Section>
        <Heading intro={p.intro}>{p.heading}</Heading>
        {shown.length === 0 ? (
          <div className="mt-8">
            <EmptyHint editing={site.editing}>
              Add a before and after photo of your real work.
            </EmptyHint>
          </div>
        ) : (
          <div className="mt-10 grid gap-8 md:grid-cols-2">
            {shown.map((item, i) => (
              <figure key={i} className="grid gap-3">
                {p.layout === 'slider' && item.before && item.after ? (
                  <Slider item={item} />
                ) : (
                  <div className="grid grid-cols-2 gap-2">
                    {photo(
                      item.before,
                      item.beforeAlt,
                      'aspect-[3/4] w-full rounded-[var(--s-radius)]',
                      'Add the before photo',
                    )}
                    {photo(
                      item.after,
                      item.afterAlt,
                      'aspect-[3/4] w-full rounded-[var(--s-radius)]',
                      'Add the after photo',
                    )}
                  </div>
                )}
                {item.caption && (
                  <figcaption className="text-[var(--s-fg-muted)]">{item.caption}</figcaption>
                )}
              </figure>
            ))}
          </div>
        )}
      </Section>
    );
  },
};
