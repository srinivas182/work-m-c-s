import type { BlockDefinition } from '../types';
import { EmptyHint, Section } from './shared';

export interface LogosProps extends Record<string, unknown> {
  heading: string;
  items: { image: string; name: string }[];
}

/** Logos (Sprint 20.2): suppliers, clients, medical aids or press — shown quietly in one row. */
export const Logos: BlockDefinition<LogosProps> = {
  type: 'Logos',
  version: 1,
  label: 'Logos',
  description:
    'Brands you stock, clients you work for, medical aids you accept, or where you’ve been featured.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    items: {
      kind: 'list',
      label: 'Logos',
      max: 10,
      item: {
        image: { kind: 'image', label: 'Logo' },
        name: { kind: 'text', label: 'Name (read out to screen readers)', max: 60 },
      },
    },
  },
  isEmpty: (p) => p.items.every((i) => !i.image),
  defaults: { heading: 'Brands we trust', items: [] },
  render: (p, site) => {
    const logos = p.items.filter((i) => i.image);
    return (
      <Section>
        {p.heading && (
          <h2 className="text-center text-sm font-semibold tracking-[0.12em] text-[var(--s-fg-muted)] uppercase">
            {p.heading}
          </h2>
        )}
        {logos.length === 0 ? (
          <div className="mt-8">
            <EmptyHint editing={site.editing}>
              Add logos of brands, clients or medical aids.
            </EmptyHint>
          </div>
        ) : (
          <ul className="mt-10 flex flex-wrap items-center justify-center gap-x-12 gap-y-8">
            {logos.map((logo, i) => (
              <li key={i}>
                <img
                  src={logo.image}
                  alt={logo.name}
                  loading="lazy"
                  className="h-10 w-auto max-w-[160px] object-contain grayscale transition hover:grayscale-0 sm:h-12"
                />
              </li>
            ))}
          </ul>
        )}
      </Section>
    );
  },
};
