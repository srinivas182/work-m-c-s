import { Star } from 'lucide-react';
import type { BlockDefinition, ReviewsSummary } from '../types';
import { EmptyHint, Heading, Section } from './shared';

export interface ReviewsProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  limit: string;
}

/** Shown only in the editor and template previews (no reviews yet), so the section still looks right. */
const SAMPLE: ReviewsSummary = {
  count: 3,
  average: 4.7,
  reviews: [
    {
      name: 'A customer',
      rating: 5,
      comment: 'Your customers’ reviews appear here, newest first.',
      reply: null,
      date: '',
    },
    {
      name: 'A customer',
      rating: 4,
      comment: 'Every review is shown — not only the best.',
      reply: 'Your replies show here too.',
      date: '',
    },
  ],
};

function Stars({ rating, size = 'size-4' }: { rating: number; size?: string }) {
  return (
    <span
      className="flex gap-0.5 text-[var(--s-brand)]"
      role="img"
      aria-label={`Rated ${rating} out of 5`}
    >
      {Array.from({ length: 5 }, (_, i) => (
        <Star
          key={i}
          aria-hidden
          className={`${size} ${i < Math.round(rating) ? 'fill-current' : 'opacity-25'}`}
        />
      ))}
    </span>
  );
}

const when = (date: string) =>
  date === ''
    ? ''
    : new Date(`${date}T12:00:00`).toLocaleDateString('en-ZA', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      });

/**
 * Reviews (Sprint 25.3b): what customers said when asked after their visit or order — every review counted in the
 * average, the shown ones newest first (never sorted by stars), with the owner's replies. Kept up to date
 * automatically when a review arrives.
 */
export const Reviews: BlockDefinition<ReviewsProps> = {
  type: 'Reviews',
  version: 1,
  label: 'Reviews',
  description:
    'Your customers’ reviews, newest first, with your replies — kept up to date automatically.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    limit: {
      kind: 'select',
      label: 'How many to show',
      options: [3, 6, 12].map((n) => ({ value: `up-to-${n}`, label: `Up to ${n}` })),
    },
  },
  defaults: { heading: 'Reviews', intro: '', limit: 'up-to-6' },
  render: (p, site) => {
    const data = site.reviews ?? (site.editing || site.reviews === undefined ? SAMPLE : null);
    if (!data || data.count === 0) {
      return site.editing ? (
        <Section>
          <Heading intro={p.intro}>{p.heading}</Heading>
          <EmptyHint editing>
            Customers are asked after each visit or order; their reviews appear here.
          </EmptyHint>
        </Section>
      ) : null;
    }
    const items = data.reviews.slice(0, Number(p.limit.replace('up-to-', '')) || 6);
    return (
      <Section>
        <Heading intro={p.intro}>{p.heading}</Heading>
        {data.average !== null && (
          <p className="mt-6 flex flex-wrap items-center gap-3">
            <Stars rating={data.average} size="size-5" />
            <span className="font-semibold">{data.average.toFixed(1)} out of 5</span>
            <span className="text-[var(--s-fg-muted)]">
              from {data.count} {data.count === 1 ? 'review' : 'reviews'}
            </span>
          </p>
        )}
        <ul className="mt-8 grid gap-4 md:grid-cols-2">
          {items.map((r, i) => (
            <li
              key={i}
              className="grid content-start gap-3 rounded-[var(--s-radius)] bg-[var(--s-surface)] p-6"
            >
              <Stars rating={r.rating} />
              {r.comment && <blockquote className="leading-relaxed">“{r.comment}”</blockquote>}
              <p className="text-sm text-[var(--s-fg-muted)]">
                <span className="font-semibold text-[var(--s-fg)]">{r.name}</span>
                {r.date && <> · {when(r.date)}</>}
              </p>
              {r.reply && (
                <p className="border-l-2 border-[var(--s-border)] pl-3 text-sm">
                  <span className="font-semibold">Reply from {site.businessName}: </span>
                  {r.reply}
                </p>
              )}
            </li>
          ))}
        </ul>
      </Section>
    );
  },
};
