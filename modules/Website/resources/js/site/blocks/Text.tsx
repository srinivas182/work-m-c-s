import type { BlockDefinition } from '../types';
import { Heading, paragraphs, Section } from './shared';

export interface TextProps extends Record<string, unknown> {
  heading: string;
  text: string;
}

/** Plain paragraphs (a blank line starts a new one). No raw HTML is ever stored or rendered. */
export const Text: BlockDefinition<TextProps> = {
  type: 'Text',
  version: 1,
  label: 'Text',
  description: 'A heading and a few paragraphs — your story, policies, anything.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 80 },
    text: { kind: 'text', label: 'Text', multiline: true, max: 4000 },
  },
  defaults: { heading: '', text: 'Write a few sentences here.' },
  render: (p) => (
    <Section narrow>
      {p.heading && <Heading>{p.heading}</Heading>}
      <div
        className={`grid gap-5 text-lg leading-relaxed text-[var(--s-fg-muted)] ${p.heading ? 'mt-6' : ''}`}
      >
        {paragraphs(p.text).map((t, i) => (
          <p key={i}>{t}</p>
        ))}
      </div>
    </Section>
  ),
};
