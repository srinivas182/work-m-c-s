import type { BlockDefinition } from '../types';
import { EmptyHint, Heading, Section } from './shared';

export interface StatsProps extends Record<string, unknown> {
  heading: string;
  items: { value: string; label: string }[];
  note: string;
}

/** The numbers that matter (Sprint 20.2): years open, customers served, a rating. Written by the owner, never counted. */
export const Stats: BlockDefinition<StatsProps> = {
  type: 'Stats',
  version: 1,
  label: 'Numbers',
  description: 'Two to four numbers that show your experience, like years open or happy customers.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading (optional)', max: 60 },
    items: {
      kind: 'list',
      label: 'Numbers',
      max: 4,
      item: {
        value: { kind: 'text', inline: true, label: 'Number', max: 12 },
        label: { kind: 'text', inline: true, label: 'What it counts', max: 40 },
      },
    },
    note: { kind: 'text', inline: true, label: 'Small print (optional)', max: 120 },
  },
  isEmpty: (p) => p.items.length === 0,
  defaults: { heading: '', items: [], note: '' },
  render: (p, site) => (
    <Section>
      {p.heading && <Heading>{p.heading}</Heading>}
      {p.items.length === 0 ? (
        <EmptyHint editing={site.editing}>Add two to four numbers, like “12 years open”.</EmptyHint>
      ) : (
        <dl
          className={`grid gap-8 sm:grid-cols-2 ${p.items.length >= 4 ? 'lg:grid-cols-4' : p.items.length === 3 ? 'lg:grid-cols-3' : ''} ${p.heading ? 'mt-12' : ''}`}
        >
          {p.items.map((item, i) => (
            <div key={i} className="border-t-2 border-[var(--s-brand)] pt-5">
              <dt className="sr-only">{item.label}</dt>
              <dd className="font-[family-name:var(--s-font-display)] text-5xl leading-none font-[var(--s-heading-weight,700)] tracking-[var(--s-heading-tracking,-0.02em)] text-[var(--s-brand-text)] sm:text-6xl">
                {item.value}
              </dd>
              <dd aria-hidden className="mt-3 text-[var(--s-fg-muted)]">
                {item.label}
              </dd>
            </div>
          ))}
        </dl>
      )}
      {p.note && <p className="mt-8 text-sm text-[var(--s-fg-muted)]">{p.note}</p>}
    </Section>
  ),
};
