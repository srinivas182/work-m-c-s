import type { BlockDefinition, ProductSummary } from '../types';
import { EmptyHint, Heading, Section, photoSrcSet } from './shared';

export interface ProductGridProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  category: string;
  limit: string;
}

/** Shown only in template previews (no business yet), so shop templates still look right. */
const SAMPLES: ProductSummary[] = [
  {
    id: 's1',
    name: 'Sample product',
    slug: '',
    category: null,
    price_cents: 25000,
    varies: true,
    compare_at_cents: null,
    photo: null,
    in_stock: true,
  },
  {
    id: 's2',
    name: 'Sample product',
    slug: '',
    category: null,
    price_cents: 18000,
    varies: false,
    compare_at_cents: 22000,
    photo: null,
    in_stock: true,
  },
  {
    id: 's3',
    name: 'Sample product',
    slug: '',
    category: null,
    price_cents: 32000,
    varies: false,
    compare_at_cents: null,
    photo: null,
    in_stock: false,
  },
];
const rand = (c: number) => `R${(c / 100).toFixed(c % 100 ? 2 : 0)}`;

export const ProductGrid: BlockDefinition<ProductGridProps> = {
  type: 'ProductGrid',
  version: 1,
  label: 'Products',
  description: 'Products from your shop, with prices — kept up to date automatically.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    category: { kind: 'text', label: 'Only this category (leave empty for all)', max: 80 },
    limit: {
      kind: 'select',
      label: 'How many to show',
      options: [3, 6, 9, 12].map((n) => ({ value: `up-to-${n}`, label: `Up to ${n}` })), // words, not numbers: the editor would coerce "3"
    },
  },
  defaults: { heading: 'Shop', intro: '', category: '', limit: 'up-to-6' },
  render: (p, site) => {
    const all = site.products ?? SAMPLES;
    const items = all
      .filter((x) => !p.category || x.category === p.category)
      .slice(0, Number(p.limit.replace('up-to-', '')) || 6);
    if (items.length === 0 && !site.editing) return null; // nothing to sell yet: hide rather than show an empty box
    return (
      <Section>
        <Heading intro={p.intro}>{p.heading}</Heading>
        {items.length === 0 ? (
          <EmptyHint editing={site.editing}>
            Add products in Shop → Products, and they’ll appear here.
          </EmptyHint>
        ) : (
          <ul className="mt-10 grid grid-cols-2 gap-4 sm:gap-6 md:grid-cols-3">
            {items.map((x) => (
              <li key={x.id}>
                <a
                  href={site.editing || !x.slug ? undefined : `/shop/${x.slug}`}
                  className="group grid gap-3"
                >
                  {x.photo ? (
                    <img
                      src={x.photo}
                      srcSet={photoSrcSet(x.photo)}
                      sizes="(min-width: 768px) 33vw, 50vw"
                      alt={x.name}
                      loading="lazy"
                      width={600}
                      height={600}
                      className="aspect-square w-full rounded-[var(--s-radius)] object-cover"
                    />
                  ) : (
                    <div
                      aria-hidden="true"
                      className="aspect-square w-full rounded-[var(--s-radius)] bg-[var(--s-surface)]"
                    />
                  )}
                  <span className="font-semibold group-hover:underline">{x.name}</span>
                  <span className="flex flex-wrap items-baseline gap-2">
                    <span>
                      {x.varies ? 'From ' : ''}
                      {rand(x.price_cents)}
                    </span>
                    {x.compare_at_cents !== null && (
                      <s className="text-sm text-[var(--s-fg-muted)]">
                        <span className="sr-only">Was </span>
                        {rand(x.compare_at_cents)}
                      </s>
                    )}
                    {!x.in_stock && <span className="text-sm font-semibold">Sold out</span>}
                  </span>
                </a>
              </li>
            ))}
          </ul>
        )}
      </Section>
    );
  },
};
