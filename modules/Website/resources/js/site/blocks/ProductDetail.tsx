import type { BlockDefinition } from '../types';
import { Section, SectionTitle, photoSrcSet } from './shared';

const rand = (c: number) => `R${(c / 100).toFixed(c % 100 ? 2 : 0)}`;

/**
 * A product's own page — internal: the renderer knows it, the editor's library never lists it (it only
 * makes sense on /shop/{slug}, where the site context carries the product).
 */
export const ProductDetail: BlockDefinition<Record<string, never>> = {
  type: 'ProductDetail',
  version: 1,
  label: 'Product',
  description: 'A product’s own page.',
  fields: {},
  defaults: {},
  render: (_p, site) => {
    const x = site.product;
    if (!x) return null;
    const [first, ...rest] = x.photos;
    return (
      <Section>
        <div className="grid gap-8 md:grid-cols-2 md:gap-12">
          <div className="grid gap-3">
            {first ? (
              <img
                src={first}
                srcSet={photoSrcSet(first)}
                sizes="(min-width: 768px) 50vw, 100vw"
                alt={x.name}
                width={900}
                height={900}
                className="aspect-square w-full rounded-[var(--s-radius)] object-cover"
              />
            ) : (
              <div
                aria-hidden="true"
                className="aspect-square w-full rounded-[var(--s-radius)] bg-[var(--s-surface)]"
              />
            )}
            {rest.length > 0 && (
              <ul className="grid grid-cols-4 gap-2">
                {rest.map((src, i) => (
                  <li key={src}>
                    <img
                      src={src}
                      srcSet={photoSrcSet(src)}
                      sizes="25vw"
                      alt={`${x.name} — photo ${i + 2}`}
                      loading="lazy"
                      width={300}
                      height={300}
                      className="aspect-square w-full rounded-[var(--s-radius)] object-cover"
                    />
                  </li>
                ))}
              </ul>
            )}
          </div>
          <div className="grid content-start gap-5">
            <SectionTitle className="font-[family-name:var(--s-font-display)] text-3xl font-bold tracking-[-0.01em] text-balance sm:text-4xl">
              {x.name}
            </SectionTitle>
            <p className="flex flex-wrap items-baseline gap-3 text-2xl">
              <span>
                {x.varies ? 'From ' : ''}
                {rand(x.price_cents)}
              </span>
              {x.compare_at_cents !== null && (
                <s className="text-lg text-[var(--s-fg-muted)]">
                  <span className="sr-only">Was </span>
                  {rand(x.compare_at_cents)}
                </s>
              )}
            </p>
            <p className="font-semibold">{x.in_stock ? 'In stock' : 'Sold out'}</p>
            {x.description &&
              x.description
                .split(/\n{2,}/)
                .filter((para) => para.trim() !== '')
                .map((para, i) => (
                  <p key={i} className="leading-relaxed text-[var(--s-fg-muted)]">
                    {para}
                  </p>
                ))}
            {x.variants.length > 1 && (
              <div className="overflow-x-auto">
                <table className="w-full text-left">
                  <caption className="mb-2 text-left font-semibold">Options and prices</caption>
                  <thead>
                    <tr className="border-b border-[var(--s-border)]">
                      <th scope="col" className="py-2 pr-4 font-medium">
                        {x.options.join(' / ') || 'Option'}
                      </th>
                      <th scope="col" className="py-2 pr-4 font-medium">
                        Price
                      </th>
                      <th scope="col" className="py-2 font-medium">
                        Available
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {x.variants.map((v) => (
                      <tr key={v.id} className="border-b border-[var(--s-border)]">
                        <th scope="row" className="py-2 pr-4 font-normal">
                          {v.label ?? x.name}
                        </th>
                        <td className="py-2 pr-4">{rand(v.price_cents)}</td>
                        <td className="py-2">{v.in_stock ? 'Yes' : 'Sold out'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {x.can_order && !site.editing ? (
              <div
                data-island="add-to-cart"
                data-product={x.name}
                data-variants={JSON.stringify(
                  x.variants.map((v) => ({
                    id: v.id,
                    label: v.label,
                    price_cents: v.price_cents,
                    in_stock: v.in_stock,
                  })),
                )}
              >
                {/* Without JavaScript: */}
                <a
                  href="/contact"
                  className="inline-flex min-h-12 items-center rounded-[var(--s-radius)] bg-[var(--s-brand)] px-7 font-semibold text-[var(--s-on-brand)]"
                >
                  Ask about this product
                </a>
              </div>
            ) : (
              <a
                href={site.editing ? undefined : '/contact'}
                className="inline-flex min-h-12 items-center justify-self-start rounded-[var(--s-radius)] bg-[var(--s-brand)] px-7 font-semibold text-[var(--s-on-brand)]"
              >
                Ask about this product
              </a>
            )}
          </div>
        </div>
      </Section>
    );
  },
};
