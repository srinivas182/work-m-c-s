import type { BlockDefinition } from '../types';
import { EmptyHint, Heading, Section } from './shared';

export interface FaqProps extends Record<string, unknown> {
  heading: string;
  items: { question: string; answer: string }[];
}

/** Native disclosure: works without JavaScript and with keyboards and screen readers. Sprint 5 adds FAQ structured data. */
export const Faq: BlockDefinition<FaqProps> = {
  type: 'Faq',
  version: 1,
  label: 'Questions and answers',
  description: 'Answer the questions customers ask most.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    items: {
      kind: 'list',
      label: 'Questions',
      max: 20,
      item: {
        question: { kind: 'text', label: 'Question', max: 140 },
        answer: { kind: 'text', inline: true, label: 'Answer', multiline: true, max: 800 },
      },
    },
  },
  defaults: { heading: 'Questions', items: [] },
  render: (p, site) => (
    <Section narrow>
      <Heading>{p.heading}</Heading>
      {p.items.length === 0 ? (
        <div className="mt-8">
          <EmptyHint editing={site.editing}>Add the questions customers ask most.</EmptyHint>
        </div>
      ) : (
        <div className="mt-8 divide-y divide-[var(--s-border)] border-y border-[var(--s-border)]">
          {p.items.map((q, i) => (
            <details key={i} className="group py-5">
              <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-6 text-lg font-semibold [&::-webkit-details-marker]:hidden">
                {q.question}
                <span
                  aria-hidden
                  className="text-2xl leading-none text-[var(--s-brand-text)] transition-transform group-open:rotate-45"
                >
                  +
                </span>
              </summary>
              <p className="mt-3 leading-relaxed whitespace-pre-line text-[var(--s-fg-muted)]">
                {q.answer}
              </p>
            </details>
          ))}
        </div>
      )}
    </Section>
  ),
};
