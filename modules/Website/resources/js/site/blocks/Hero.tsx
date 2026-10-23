import { photoSrcSet } from './shared';
import { ImagePlus } from 'lucide-react';
import type { ReactNode } from 'react';
import type { BlockDefinition, SiteContext } from '../types';
import { HeroSlideshow, type Photo } from './HeroSlideshow';

export type HeroLayout = 'photo' | 'split' | 'collage' | 'slideshow' | 'centered';

export interface HeroProps extends Record<string, unknown> {
  layout: HeroLayout;
  heading: string;
  text: string;
  primaryLabel: string;
  primaryHref: string;
  secondaryLabel: string;
  secondaryHref: string;
  /** Practical facts a local customer looks for first, e.g. "Open today until 18:00". */
  facts: { text: string }[];
  /** Up to 5; how many are used depends on the layout. */
  images: Photo[];
  /** Slideshow only: advance on its own (slowly, pausable, never with reduced motion). */
  autoplay: boolean;
}

const PHOTOS_USED: Record<HeroLayout, number> = {
  photo: 1,
  split: 1,
  collage: 3,
  slideshow: 5,
  centered: 0,
};

function AddPhotos({ count, className = '' }: { count: number; className?: string }) {
  return (
    <div
      className={`grid place-items-center rounded-[var(--s-radius)] border-2 border-dashed border-[var(--s-border)] bg-[var(--s-brand-softer)] text-[var(--s-brand-text)] ${className}`}
    >
      <span className="flex flex-col items-center gap-2 px-6 text-center text-sm font-medium">
        <ImagePlus className="size-7" aria-hidden />
        {count > 1 ? `Add up to ${count} photos of your business` : 'Add a photo of your business'}
      </span>
    </div>
  );
}

function Copy({
  p,
  site,
  align = 'start',
  onPhoto = false,
}: {
  p: HeroProps;
  site: SiteContext;
  align?: 'start' | 'center';
  onPhoto?: boolean;
}) {
  const center = align === 'center';
  const muted = onPhoto ? 'text-white/85' : 'text-[var(--s-fg-muted)]';
  return (
    <div className={center ? 'mx-auto max-w-2xl text-center' : 'max-w-2xl'}>
      <h1 className="font-[family-name:var(--s-font-display)] text-[2.5rem] leading-[1.05] font-bold tracking-[-0.02em] text-balance sm:text-6xl">
        {p.heading}
      </h1>
      <p className={`mt-5 text-lg leading-relaxed text-pretty ${muted}`}>{p.text}</p>
      <div
        className={`mt-8 flex flex-wrap items-center gap-x-6 gap-y-3 ${center ? 'justify-center' : ''}`}
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
            className={`inline-flex min-h-12 items-center font-semibold underline-offset-4 hover:underline ${onPhoto ? 'text-white' : 'text-[var(--s-brand-text)]'}`}
          >
            {p.secondaryLabel}
          </a>
        )}
      </div>
      {p.facts.length > 0 && (
        <ul
          className={`mt-8 flex flex-wrap gap-x-5 gap-y-2 text-sm ${muted} ${center ? 'justify-center' : ''}`}
          aria-label="Quick facts"
        >
          {p.facts.map((f, i) => (
            <li key={i} className="flex items-center gap-2">
              <span
                aria-hidden
                className={`size-1.5 rounded-full ${onPhoto ? 'bg-white' : 'bg-[var(--s-brand)]'}`}
              />
              {f.text}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

const frame = (children: ReactNode) => (
  <section className="px-5 py-16 sm:px-8 sm:py-24">
    <div className="mx-auto max-w-6xl">{children}</div>
  </section>
);

export const Hero: BlockDefinition<HeroProps> = {
  type: 'Hero',
  version: 2,
  label: 'Hero',
  description:
    'The first thing visitors see: what you do, where, and how to book — with your best photos.',
  fields: {
    layout: {
      kind: 'select',
      label: 'Layout',
      options: [
        { value: 'photo', label: 'Full photo' },
        { value: 'split', label: 'Text and photo' },
        { value: 'collage', label: 'Photo collage' },
        { value: 'slideshow', label: 'Slideshow' },
        { value: 'centered', label: 'Text only' },
      ],
    },
    heading: { kind: 'text', inline: true, label: 'Heading', max: 90 },
    text: { kind: 'text', inline: true, label: 'Text', multiline: true, max: 280 },
    primaryLabel: { kind: 'text', inline: true, label: 'Main button', max: 30 },
    primaryHref: { kind: 'link', label: 'Main button link' },
    secondaryLabel: { kind: 'text', inline: true, label: 'Second link', max: 30 },
    secondaryHref: { kind: 'link', label: 'Second link goes to' },
    facts: {
      kind: 'list',
      label: 'Quick facts',
      item: { text: { kind: 'text', inline: true, label: 'Fact', max: 50 } },
      max: 3,
    },
    images: {
      kind: 'list',
      label: 'Photos',
      max: 5,
      item: {
        src: { kind: 'image', label: 'Photo' },
        alt: { kind: 'text', label: 'Describe the photo (for screen readers)', max: 140 },
      },
    },
    autoplay: {
      kind: 'select',
      label: 'Slideshow moves on its own',
      options: [
        { value: false, label: 'No — visitors swipe (recommended)' },
        { value: true, label: 'Yes, slowly, with a pause button' },
      ],
    },
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
    images: [],
    autoplay: false,
  },
  // v1 stored a single photo as image / imageAlt.
  upgrade: (props, from) => {
    if (from === 1) {
      const src = typeof props.image === 'string' ? props.image : '';
      const alt = typeof props.imageAlt === 'string' ? props.imageAlt : '';
      const rest = { ...props };
      delete rest.image;
      delete rest.imageAlt;
      return { ...rest, images: src ? [{ src, alt }] : [], autoplay: false };
    }
    return props;
  },
  render: (p, site) => {
    const photos = p.images.filter((i) => i.src).slice(0, PHOTOS_USED[p.layout]);
    const wanted = PHOTOS_USED[p.layout];

    // Without photos on a live site every layout falls back to a strong text-only hero.
    if (wanted === 0 || (photos.length === 0 && !site.editing)) {
      return frame(
        p.layout === 'centered' ? (
          <Copy p={p} site={site} align="center" />
        ) : (
          <Copy p={p} site={site} />
        ),
      );
    }

    if (p.layout === 'photo') {
      const photo = photos[0];
      return (
        <section className="relative isolate flex min-h-[560px] items-end overflow-hidden px-5 pt-40 pb-14 text-white sm:min-h-[680px] sm:px-8 sm:pb-20">
          {photo ? (
            <img
              src={photo.src}
              srcSet={photoSrcSet(photo.src)}
              sizes="100vw"
              alt={photo.alt}
              fetchPriority="high"
              className="absolute inset-0 -z-20 size-full object-cover"
            />
          ) : (
            <AddPhotos
              count={1}
              className="absolute inset-4 -z-20 border-white/40 bg-[var(--s-brand-text)] text-white"
            />
          )}
          {/* Shading strong enough for white text on any photo (contrast ≥ 4.5:1 where the text sits). */}
          <div
            aria-hidden
            className="absolute inset-0 -z-10 bg-gradient-to-t from-black/80 via-black/45 to-black/10"
          />
          <div className="mx-auto w-full max-w-6xl">
            <Copy p={p} site={site} onPhoto />
          </div>
        </section>
      );
    }

    if (p.layout === 'collage') {
      const [a, b, c] = photos;
      const tile = (ph: Photo | undefined, cls: string) =>
        ph ? (
          <img
            src={ph.src}
            srcSet={photoSrcSet(ph.src)}
            sizes="(min-width: 768px) 30vw, 60vw"
            alt={ph.alt}
            loading="lazy"
            className={`size-full rounded-[var(--s-radius)] object-cover ${cls}`}
          />
        ) : (
          <AddPhotos count={3} className={cls} />
        );
      return frame(
        <div className="grid items-center gap-12 md:grid-cols-[1fr_1.1fr]">
          <Copy p={p} site={site} />
          <div className="grid aspect-[5/4] grid-cols-[1.4fr_1fr] grid-rows-2 gap-3 sm:gap-4">
            <div className="row-span-2">
              {a ? (
                <img
                  src={a.src}
                  srcSet={photoSrcSet(a.src)}
                  sizes="(min-width: 768px) 30vw, 60vw"
                  alt={a.alt}
                  fetchPriority="high"
                  className="size-full rounded-[var(--s-radius)] object-cover"
                />
              ) : (
                <AddPhotos count={3} className="size-full" />
              )}
            </div>
            <div>{tile(b, 'size-full')}</div>
            <div>{tile(c, 'size-full')}</div>
          </div>
        </div>,
      );
    }

    if (p.layout === 'slideshow') {
      return frame(
        <div className="grid items-center gap-12 md:grid-cols-[1.05fr_1fr]">
          <Copy p={p} site={site} />
          {photos.length > 0 ? (
            <HeroSlideshow
              photos={photos}
              autoplay={p.autoplay}
              label={`Photos of ${site.businessName}`}
            />
          ) : (
            <AddPhotos count={5} className="aspect-[4/5] sm:aspect-[5/6]" />
          )}
        </div>,
      );
    }

    // split
    const photo = photos[0];
    return frame(
      <div className="grid items-center gap-12 md:grid-cols-[1.1fr_1fr]">
        <Copy p={p} site={site} />
        {photo ? (
          <img
            src={photo.src}
            srcSet={photoSrcSet(photo.src)}
            sizes="(min-width: 768px) 45vw, 100vw"
            alt={photo.alt}
            fetchPriority="high"
            className="aspect-[4/5] w-full rounded-[var(--s-radius)] object-cover sm:aspect-[5/6]"
          />
        ) : (
          <AddPhotos count={1} className="aspect-[4/5] sm:aspect-[5/6]" />
        )}
      </div>,
    );
  },
};
