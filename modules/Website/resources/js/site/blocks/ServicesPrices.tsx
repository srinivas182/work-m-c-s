import { rand } from '@/lib/money';
import type { BlockDefinition } from '../types';
import { SectionTitle } from './shared';

export interface ServiceItem {
  name: string;
  description: string;
  duration: string;
  /** Price in cents (ZAR); 0 hides the price ("ask us"). */
  price: number;
  from: boolean;
}

export interface ServicesPricesProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  items: ServiceItem[];
  note: string;
}

export const ServicesPrices: BlockDefinition<ServicesPricesProps> = {
  type: 'ServicesPrices',
  version: 1,
  label: 'Services and prices',
  description: 'A clear price list, set out like a printed menu.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', multiline: true, max: 240 },
    items: {
      kind: 'list',
      label: 'Services',
      max: 40,
      item: {
        name: { kind: 'text', inline: true, label: 'Service', max: 60 },
        description: { kind: 'text', inline: true, label: 'Description', max: 140 },
        duration: { kind: 'text', label: 'Duration', max: 20 },
        price: { kind: 'money', label: 'Price' },
        from: {
          kind: 'select',
          label: 'Price is',
          options: [
            { value: false, label: 'Fixed' },
            { value: true, label: 'From' },
          ],
        },
      },
    },
    note: { kind: 'text', inline: true, label: 'Note under the list', max: 160 },
  },
  isEmpty: (p) => p.items.length === 0,
  defaults: {
    heading: 'Services and prices',
    intro: '',
    items: [{ name: 'Your first service', description: '', duration: '', price: 0, from: false }],
    note: '',
  },
  render: (p) => (
    <section id="prices" className="bg-[var(--s-surface)] px-5 py-16 sm:px-8 sm:py-24">
      {/* Same left edge as the other sections; the list keeps a comfortable reading width. */}
      <div className="mx-auto max-w-6xl">
        <div className="max-w-3xl">
          <SectionTitle className="font-[family-name:var(--s-font-display)] text-3xl font-bold tracking-[-0.01em] sm:text-4xl">
            {p.heading}
          </SectionTitle>
          {p.intro && <p className="mt-4 text-lg text-[var(--s-fg-muted)]">{p.intro}</p>}
          <ul className="mt-10 grid gap-7">
            {p.items.map((item, i) => (
              <li key={i}>
                <div className="flex items-baseline gap-3">
                  <span className="font-semibold">{item.name}</span>
                  <span
                    aria-hidden
                    className="min-w-6 flex-1 translate-y-[-0.3em] border-b-2 border-dotted border-[var(--s-border)]"
                  />
                  <span className="shrink-0 font-semibold text-[var(--s-brand-text)] tabular-nums">
                    {item.price > 0 ? `${item.from ? 'from ' : ''}${rand(item.price)}` : 'Ask us'}
                  </span>
                </div>
                {(item.description || item.duration) && (
                  <p className="mt-1 max-w-[85%] text-[15px] text-[var(--s-fg-muted)]">
                    {item.description}
                    {item.description && item.duration ? ' · ' : ''}
                    {item.duration}
                  </p>
                )}
              </li>
            ))}
          </ul>
          {p.note && (
            <p className="mt-10 border-t border-[var(--s-border)] pt-5 text-sm text-[var(--s-fg-muted)]">
              {p.note}
            </p>
          )}
        </div>
      </div>
    </section>
  ),
};
