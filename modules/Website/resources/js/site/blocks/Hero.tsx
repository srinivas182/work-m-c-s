import { ImagePlus } from 'lucide-react';
import type { BlockDefinition } from '../types';

export interface HeroProps extends Record<string, unknown> {
  layout: 'split' | 'centered';
  heading: string;
  text: string;
  primaryLabel: string;
  primaryHref: string;
  secondaryLabel: string;
  secondaryHref: string;
  /** Practical facts a local customer looks for first, e.g. "Open today until 18:00". */
  facts: { text: string }[];
  image: string;
  imageAlt: string;
}

function Photo({ src, alt, editing }: { src: string; alt: string; editing: boolean }) {
  if (src) {
    return (
      <img
        src={src}
        alt={alt}
        className="aspect-[4/5] w-full rounded-[var(--s-radius)] object-cover sm:aspect-[5/6]"
      />
    );
  }
  if (!editing) return null; // no stand-in pictures on a live site
  return (
    <div className="grid aspect-[4/5] w-full place-items-center rounded-[var(--s-radius)] border-2 border-dashed border-[var(--s-border)] bg-[var(--s-brand-softer)] text-[var(--s-brand-text)] sm:aspect-[5/6]">
      <span className="flex flex-col items-center gap-2 text-sm font-medium">
        <ImagePlus className="size-7" aria-hidden />
        Add a photo of your business
      </span>
    </div>
  );
}

export const Hero: BlockDefinition<HeroProps> = {
  type: 'Hero',
  version: 1,
  label: 'Hero',
  description: 'The first thing visitors see: what you do, where, and how to book.',
  fields: {
    layout: {
      kind: 'select',
      label: 'Layout',
      options: [
        { value: 'split', label: 'Text and photo' },
        { value: 'centered', label: 'Centred text' },
      ],
    },
    heading: { kind: 'text', label: 'Heading', max: 90 },
    text: { kind: 'text', label: 'Text', multiline: true, max: 280 },
    primaryLabel: { kind: 'text', label: 'Main button', max: 30 },
    primaryHref: { kind: 'link', label: 'Main button link' },
    secondaryLabel: { kind: 'text', label: 'Second link', max: 30 },
    secondaryHref: { kind: 'link', label: 'Second link goes to' },
    facts: {
      kind: 'list',
      label: 'Quick facts',
      item: { text: { kind: 'text', label: 'Fact', max: 50 } },
      max: 3,
    },
    image: { kind: 'image', label: 'Photo' },
    imageAlt: { kind: 'text', label: 'Describe the photo (for screen readers)', max: 140 },
  },
  defaults: {
    layout: 'split',
    heading: 'Your business, at its best',
    text: 'Say in one or two sentences what you do and who it’s for.',
    primaryLabel: 'Book now',
    primaryHref: '#book',
    secondaryLabel: 'See prices',
    secondaryHref: '#prices',
    facts: [],
    image: '',
    imageAlt: '',
  },
  render: (p, site) => {
    const centered = p.layout === 'centered';
    // On a live site without a photo, the split layout uses the full width instead of an empty column.
    const showPhoto = !centered && (p.image !== '' || site.editing);
    const copy = (
      <div className={centered ? 'mx-auto max-w-2xl text-center' : ''}>
        <h1 className="font-[family-name:var(--s-font-display)] text-[2.5rem] leading-[1.05] font-bold tracking-[-0.02em] text-balance sm:text-6xl">
          {p.heading}
        </h1>
        <p className="mt-5 text-lg leading-relaxed text-pretty text-[var(--s-fg-muted)]">
          {p.text}
        </p>
        <div
          className={`mt-8 flex flex-wrap items-center gap-x-6 gap-y-3 ${centered ? 'justify-center' : ''}`}
        >
          {p.primaryLabel && (
            <a
              href={site.editing ? undefined : p.primaryHref}
              className="inline-flex min-h-12 items-center rounded-[var(--s-radius)] bg-[var(--s-brand)] px-6 font-semibold text-[var(--s-on-brand)] shadow-sm transition-opacity hover:opacity-90"
            >
              {p.primaryLabel}
            </a>
          )}
          {p.secondaryLabel && (
            <a
              href={site.editing ? undefined : p.secondaryHref}
              className="inline-flex min-h-12 items-center font-semibold text-[var(--s-brand-text)] underline-offset-4 hover:underline"
            >
              {p.secondaryLabel}
            </a>
          )}
        </div>
        {p.facts.length > 0 && (
          <ul
            className={`mt-8 flex flex-wrap gap-x-5 gap-y-2 text-sm text-[var(--s-fg-muted)] ${centered ? 'justify-center' : ''}`}
            aria-label="Quick facts"
          >
            {p.facts.map((f, i) => (
              <li key={i} className="flex items-center gap-2">
                <span aria-hidden className="size-1.5 rounded-full bg-[var(--s-brand)]" />
                {f.text}
              </li>
            ))}
          </ul>
        )}
      </div>
    );

    return (
      <section className="px-5 py-16 sm:px-8 sm:py-24">
        {centered || !showPhoto ? (
          <div className="mx-auto max-w-6xl">
            {centered ? copy : <div className="max-w-3xl">{copy}</div>}
          </div>
        ) : (
          <div className="mx-auto grid max-w-6xl items-center gap-12 md:grid-cols-[1.1fr_1fr]">
            {copy}
            <Photo src={p.image} alt={p.imageAlt} editing={site.editing} />
          </div>
        )}
      </section>
    );
  },
};
