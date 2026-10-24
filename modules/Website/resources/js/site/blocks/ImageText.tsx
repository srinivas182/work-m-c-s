import type { BlockDefinition } from '../types';
import { paragraphs, PhotoPrompt, Section, photoSrcSet, SectionTitle } from './shared';

export interface ImageTextProps extends Record<string, unknown> {
  heading: string;
  text: string;
  image: string;
  imageAlt: string;
  imageSide: 'left' | 'right';
  buttonLabel: string;
  buttonHref: string;
}

export const ImageText: BlockDefinition<ImageTextProps> = {
  type: 'ImageText',
  version: 1,
  label: 'Photo and text',
  description: 'A photo beside a short story — your space, your team, your work.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 80 },
    text: { kind: 'text', label: 'Text', multiline: true, max: 1200 },
    image: { kind: 'image', label: 'Photo' },
    imageAlt: { kind: 'text', label: 'Describe the photo (for screen readers)', max: 140 },
    imageSide: {
      kind: 'select',
      label: 'Photo on the',
      options: [
        { value: 'left', label: 'Left' },
        { value: 'right', label: 'Right' },
      ],
    },
    buttonLabel: { kind: 'text', inline: true, label: 'Button (optional)', max: 30 },
    buttonHref: { kind: 'link', label: 'Button link' },
  },
  defaults: {
    heading: 'About us',
    text: 'Tell visitors who you are and what makes you different.',
    image: '',
    imageAlt: '',
    imageSide: 'left',
    buttonLabel: '',
    buttonHref: '',
  },
  render: (p, site) => {
    const photo = p.image ? (
      <img
        src={p.image}
        srcSet={photoSrcSet(p.image)}
        sizes="(min-width: 768px) 50vw, 100vw"
        alt={p.imageAlt}
        loading="lazy"
        className="aspect-[4/3] w-full rounded-[var(--s-radius)] object-cover"
      />
    ) : (
      <PhotoPrompt editing={site.editing} className="aspect-[4/3] w-full" />
    );
    const hasPhoto = p.image !== '' || site.editing;
    return (
      <Section>
        <div
          className={`grid items-center gap-10 lg:gap-16 ${hasPhoto ? 'md:grid-cols-2' : 'max-w-3xl'}`}
        >
          {hasPhoto && <div className={p.imageSide === 'right' ? 'md:order-2' : ''}>{photo}</div>}
          <div>
            <SectionTitle className="font-[family-name:var(--s-font-display)] text-3xl font-bold tracking-[-0.01em] text-balance sm:text-4xl">
              {p.heading}
            </SectionTitle>
            <div className="mt-5 grid gap-4 text-lg leading-relaxed text-[var(--s-fg-muted)]">
              {paragraphs(p.text).map((t, i) => (
                <p key={i}>{t}</p>
              ))}
            </div>
            {p.buttonLabel && (
              <a
                href={site.editing ? undefined : p.buttonHref}
                className="mt-8 inline-flex min-h-12 items-center rounded-[var(--s-radius)] border-2 border-[var(--s-brand)] px-6 font-semibold text-[var(--s-brand-text)]"
              >
                {p.buttonLabel}
              </a>
            )}
          </div>
        </div>
      </Section>
    );
  },
};
