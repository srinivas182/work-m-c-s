import type { BlockDefinition } from '../types';
import { Heading, PhotoPrompt, Section, photoSrcSet } from './shared';

export interface GalleryProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  images: { src: string; alt: string }[];
}

/** A balanced mosaic: the first photo is featured, the rest fill in around it. */
/** Column spans (as literal classes, for Tailwind) so the grid never leaves a gap. */
const COLS = {
  1: 'sm:col-span-1',
  2: 'sm:col-span-2',
  3: 'sm:col-span-3',
  4: 'sm:col-span-4',
} as const;

/**
 * The first photo is a 2×2 feature. Phones (2 columns): the last photo stretches if the
 * rest would leave a hole. Desktop (4 columns): the four cells beside the feature are
 * filled exactly, and the last row's final photo stretches to the end of its row.
 */
export function gallerySpan(i: number, n: number): string {
  if (i === 0) return 'col-span-2 row-span-2';
  const rest = n - 1;
  const phone = i === n - 1 && rest % 2 === 1 ? 'col-span-2' : '';
  const k = i - 1; // position among the rest
  let desktop: string;
  if (k < 4) {
    const beside = Math.min(rest, 4);
    desktop =
      beside === 1
        ? 'sm:col-span-2 sm:row-span-2'
        : beside === 2 || (beside === 3 && k === 2)
          ? COLS[2]
          : COLS[1];
  } else {
    const below = rest - 4;
    const inLast = below % 4;
    desktop = inLast !== 0 && k === rest - 1 ? COLS[(5 - inLast) as 2 | 3 | 4] : COLS[1];
  }
  return `${phone} ${desktop}`.trim();
}

export const Gallery: BlockDefinition<GalleryProps> = {
  type: 'Gallery',
  version: 1,
  label: 'Gallery',
  description: 'Show your work — up to 12 photos in a balanced mosaic.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    images: {
      kind: 'list',
      label: 'Photos',
      max: 12,
      item: {
        src: { kind: 'image', label: 'Photo' },
        alt: { kind: 'text', label: 'Describe the photo', max: 140 },
      },
    },
  },
  defaults: { heading: 'Our work', intro: '', images: [] },
  render: (p, site) => {
    const photos = p.images.filter((i) => i.src);
    if (photos.length === 0 && !site.editing) return null;
    return (
      <Section>
        <Heading intro={p.intro}>{p.heading}</Heading>
        {photos.length === 0 ? (
          <div className="mt-10 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <PhotoPrompt
              editing
              className="col-span-2 row-span-2 aspect-square"
              text="Add up to 12 photos"
            />
            <PhotoPrompt editing className="aspect-square" text="" />
            <PhotoPrompt editing className="aspect-square" text="" />
          </div>
        ) : (
          <ul className="mt-10 grid auto-rows-[160px] grid-cols-2 gap-3 sm:auto-rows-[220px] sm:grid-cols-4 sm:gap-4">
            {photos.map((ph, i) => (
              <li key={i} className={gallerySpan(i, photos.length)}>
                <img
                  src={ph.src}
                  srcSet={photoSrcSet(ph.src)}
                  sizes="(min-width: 768px) 33vw, 50vw"
                  alt={ph.alt}
                  loading="lazy"
                  className="size-full rounded-[var(--s-radius)] object-cover"
                />
              </li>
            ))}
          </ul>
        )}
      </Section>
    );
  },
};
