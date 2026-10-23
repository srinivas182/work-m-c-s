import type { BlockDefinition } from '../types';

export interface CallToActionProps extends Record<string, unknown> {
  heading: string;
  text: string;
  buttonLabel: string;
  buttonHref: string;
}

export const CallToAction: BlockDefinition<CallToActionProps> = {
  type: 'CallToAction',
  version: 1,
  label: 'Call to action',
  description: 'A bold band that asks visitors to book, call or visit.',
  fields: {
    heading: { kind: 'text', label: 'Heading', max: 80 },
    text: { kind: 'text', label: 'Text', max: 200 },
    buttonLabel: { kind: 'text', label: 'Button', max: 30 },
    buttonHref: { kind: 'link', label: 'Button link' },
  },
  defaults: {
    heading: 'Ready when you are',
    text: 'Book online in under a minute.',
    buttonLabel: 'Book now',
    buttonHref: '#book',
  },
  render: (p, site) => (
    <section className="px-5 py-10 sm:px-8 sm:py-14">
      <div className="mx-auto flex max-w-6xl flex-col items-start justify-between gap-8 rounded-[calc(var(--s-radius)*1.5)] bg-[var(--s-brand)] px-8 py-12 text-[var(--s-on-brand)] sm:px-12 md:flex-row md:items-center">
        <div className="max-w-2xl">
          <h2 className="font-[family-name:var(--s-font-display)] text-3xl font-bold tracking-[-0.01em] text-balance sm:text-4xl">
            {p.heading}
          </h2>
          {p.text && <p className="mt-3 text-lg opacity-90">{p.text}</p>}
        </div>
        {p.buttonLabel && (
          <a
            href={site.editing ? undefined : p.buttonHref}
            className="inline-flex min-h-12 shrink-0 items-center rounded-[var(--s-radius)] bg-[var(--s-on-brand)] px-7 font-semibold text-[var(--s-brand-text)]"
          >
            {p.buttonLabel}
          </a>
        )}
      </div>
    </section>
  ),
};
