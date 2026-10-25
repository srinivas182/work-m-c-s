import type { BlockDefinition } from '../types';
import { Heading, Section } from './shared';

export interface BookOnlineProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  fallbackText: string;
  fallbackLabel: string;
  fallbackHref: string;
}

/**
 * Online booking. The page carries a plain fallback (“call or message us to book”) that works
 * without JavaScript; on the live site a small island replaces it with the booking steps, using
 * the business's own booking API (/book). Static preview in the editor.
 */
export const BookOnline: BlockDefinition<BookOnlineProps> = {
  type: 'BookOnline',
  version: 1,
  label: 'Book online',
  description:
    'Customers choose a service and a free time, and book. Set services and hours in Booking settings.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    fallbackText: { kind: 'text', label: 'If online booking can’t load', max: 120 },
    fallbackLabel: { kind: 'text', label: 'Link text', max: 40 },
    fallbackHref: { kind: 'link', label: 'Link goes to' },
  },
  defaults: {
    heading: 'Book online',
    intro: 'Choose a service and a time that suits you.',
    fallbackText: 'Call or message us to book.',
    fallbackLabel: 'Contact us',
    fallbackHref: '/contact',
  },
  render: (p, site) => (
    <Section id="book">
      <Heading intro={p.intro}>{p.heading}</Heading>
      {site.editing ? (
        <ol
          aria-label="Booking steps (preview)"
          className="grid gap-2 rounded-[var(--s-radius)] border border-dashed border-[var(--s-border)] p-6 text-[var(--s-fg-muted)]"
        >
          <li>1. Choose a service</li>
          <li>2. Pick a day and a free time</li>
          <li>3. Add your details and book</li>
          <li className="text-sm">
            Online booking works on your live site, using your Booking settings.
          </li>
        </ol>
      ) : (
        <div
          data-island="booking"
          data-api="/book"
          className="rounded-[var(--s-radius)] border border-[var(--s-border)] p-6"
        >
          <p data-booking-fallback>
            {p.fallbackText}{' '}
            <a
              href={p.fallbackHref}
              className="font-semibold text-[var(--s-brand-text)] underline underline-offset-4"
            >
              {p.fallbackLabel}
            </a>
          </p>
        </div>
      )}
    </Section>
  ),
};
