import type { BlockDefinition } from '../types';
import { EmptyHint, Heading, Section } from './shared';

export interface StepsProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  items: { title: string; text: string }[];
}

/** How it works (Sprint 20.2): numbered steps, so a first-time customer knows what happens next. */
export const Steps: BlockDefinition<StepsProps> = {
  type: 'Steps',
  version: 1,
  label: 'How it works',
  description: 'Three to six numbered steps: what happens from first contact to done.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', multiline: true, max: 200 },
    items: {
      kind: 'list',
      label: 'Steps',
      max: 6,
      item: {
        title: { kind: 'text', inline: true, label: 'Step', max: 50 },
        text: { kind: 'text', inline: true, label: 'Text', multiline: true, max: 180 },
      },
    },
  },
  isEmpty: (p) => p.items.length === 0,
  defaults: { heading: 'How it works', intro: '', items: [] },
  render: (p, site) => (
    <Section>
      <Heading intro={p.intro}>{p.heading}</Heading>
      {p.items.length === 0 ? (
        <div className="mt-10">
          <EmptyHint editing={site.editing}>Add three to six steps, in order.</EmptyHint>
        </div>
      ) : (
        <ol className="mt-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-3">
          {p.items.map((item, i) => (
            <li key={i} className="relative">
              <span
                aria-hidden
                className="font-[family-name:var(--s-font-display)] text-4xl leading-none text-[var(--s-brand-text)]"
              >
                {String(i + 1).padStart(2, '0')}
              </span>
              <h3 className="mt-4 text-lg font-semibold">{item.title}</h3>
              <p className="mt-2 leading-relaxed text-[var(--s-fg-muted)]">{item.text}</p>
            </li>
          ))}
        </ol>
      )}
    </Section>
  ),
};
