import type { BlockDefinition } from '../types';
import { Heading, PhotoPrompt, Section } from './shared';

export interface GalleryProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  images: { src: string; alt: string }[];
}

/** A balanced mosaic: the first photo is featured, the rest fill in around it. */
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
              <li key={i} className={i === 0 ? 'col-span-2 row-span-2' : ''}>
                <img
                  src={ph.src}
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
