import { BadgeCheck } from 'lucide-react';
import type { BlockDefinition } from '../types';
import { EmptyHint, Heading, Section } from './shared';

export interface AccreditationsProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  items: { name: string; logo: string; detail: string }[];
}

export const Accreditations: BlockDefinition<AccreditationsProps> = {
  type: 'Accreditations',
  version: 1,
  label: 'Accreditations',
  description: 'Registrations, medical aids, brands you’re approved for.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    items: {
      kind: 'list',
      label: 'Accreditations',
      max: 16,
      item: {
        name: { kind: 'text', label: 'Name (e.g. SAICA, Discovery Health)', max: 60 },
        logo: { kind: 'image', label: 'Logo (optional)' },
        detail: { kind: 'text', inline: true, label: 'Detail (e.g. member no. 12345)', max: 60 },
      },
    },
  },
  defaults: { heading: 'Registered and accredited', intro: '', items: [] },
  render: (p, site) => (
    <Section tone="surface">
      <Heading intro={p.intro}>{p.heading}</Heading>
      {p.items.length === 0 ? (
        <div className="mt-8">
          <EmptyHint editing={site.editing}>
            Add the registrations and partners customers look for.
          </EmptyHint>
        </div>
      ) : (
        <ul className="mt-10 flex flex-wrap gap-4" aria-label="Accreditations">
          {p.items.map((a, i) => (
            <li
              key={i}
              className="flex min-h-16 items-center gap-3 rounded-[var(--s-radius)] border border-[var(--s-border)] bg-[var(--s-bg)] px-4 py-3"
            >
              {a.logo ? (
                <img
                  src={a.logo}
                  alt={a.name}
                  loading="lazy"
                  className="h-10 w-auto max-w-[8rem] object-contain"
                />
              ) : (
                <BadgeCheck className="size-6 text-[var(--s-brand)]" aria-hidden />
              )}
              <span>
                {!a.logo && <span className="block font-semibold">{a.name}</span>}
                {a.detail && <span className="text-sm text-[var(--s-fg-muted)]">{a.detail}</span>}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Section>
  ),
};
