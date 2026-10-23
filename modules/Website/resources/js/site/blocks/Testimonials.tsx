import { Star } from 'lucide-react';
import type { BlockDefinition } from '../types';

export interface Testimonial {
  quote: string;
  name: string;
  detail: string;
  /** 0 = no rating shown, otherwise 1–5. */
  rating: number;
}

export interface TestimonialsProps extends Record<string, unknown> {
  heading: string;
  items: Testimonial[];
}

function Stars({ rating }: { rating: number }) {
  if (rating < 1) return null;
  return (
    <p
      className="flex gap-0.5 text-[var(--s-brand)]"
      role="img"
      aria-label={`Rated ${rating} out of 5`}
    >
      {Array.from({ length: 5 }, (_, i) => (
        <Star
          key={i}
          aria-hidden
          className={`size-4 ${i < rating ? 'fill-current' : 'opacity-25'}`}
        />
      ))}
    </p>
  );
}

export const Testimonials: BlockDefinition<TestimonialsProps> = {
  type: 'Testimonials',
  version: 1,
  label: 'Testimonials',
  description: 'What customers say — one featured, others alongside.',
  fields: {
    heading: { kind: 'text', label: 'Heading', max: 60 },
    items: {
      kind: 'list',
      label: 'Testimonials',
      max: 6,
      item: {
        quote: { kind: 'text', label: 'What they said', multiline: true, max: 320 },
        name: { kind: 'text', label: 'Name', max: 60 },
        detail: { kind: 'text', label: 'Detail (e.g. “Client since 2021”)', max: 60 },
        rating: {
          kind: 'select',
          label: 'Rating',
          options: [
            { value: '0', label: 'None' },
            ...[5, 4, 3, 2, 1].map((n) => ({ value: String(n), label: `${n} stars` })),
          ],
        },
      },
    },
  },
  defaults: { heading: 'What our customers say', items: [] },
  render: (p, site) => {
    const [featured, ...rest] = p.items;
    if (!featured) {
      return site.editing ? (
        <section className="px-5 py-16 text-center text-[var(--s-fg-muted)] sm:px-8">
          Add a testimonial from a real customer.
        </section>
      ) : null;
    }
    return (
      <section className="px-5 py-16 sm:px-8 sm:py-24">
        <div className="mx-auto max-w-6xl">
          <h2 className="font-[family-name:var(--s-font-display)] text-3xl font-bold tracking-[-0.01em] sm:text-4xl">
            {p.heading}
          </h2>
          {/* A lone quote keeps a readable line length instead of spanning the page. */}
          <div
            className={`mt-10 grid gap-6 ${rest.length > 0 ? 'lg:grid-cols-[1.4fr_1fr]' : 'max-w-3xl'}`}
          >
            <figure className="flex flex-col justify-between gap-8 rounded-[var(--s-radius)] bg-[var(--s-brand-softer)] p-8 sm:p-10">
              <div className="grid gap-5">
                <Stars rating={featured.rating} />
                <blockquote className="font-[family-name:var(--s-font-display)] text-2xl leading-snug sm:text-[1.75rem]">
                  “{featured.quote}”
                </blockquote>
              </div>
              <figcaption>
                <span className="block font-semibold">{featured.name}</span>
                {featured.detail && (
                  <span className="text-sm text-[var(--s-fg-muted)]">{featured.detail}</span>
                )}
              </figcaption>
            </figure>
            {rest.length > 0 && (
              <div className="grid content-start gap-6">
                {rest.slice(0, 4).map((t, i) => (
                  <figure
                    key={i}
                    className="grid gap-4 rounded-[var(--s-radius)] border border-[var(--s-border)] p-6"
                  >
                    <Stars rating={t.rating} />
                    <blockquote className="text-[17px] leading-relaxed">“{t.quote}”</blockquote>
                    <figcaption className="text-sm">
                      <span className="font-semibold">{t.name}</span>
                      {t.detail && <span className="text-[var(--s-fg-muted)]"> · {t.detail}</span>}
                    </figcaption>
                  </figure>
                ))}
              </div>
            )}
          </div>
        </div>
      </section>
    );
  },
};
