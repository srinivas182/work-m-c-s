import { rand } from '@/lib/money';
import type { BlockDefinition } from '../types';
import { EmptyHint, Heading, Section } from './shared';

const TAGS = ['Vegetarian', 'Vegan', 'Halaal', 'Gluten-free', 'Contains nuts', 'Spicy'] as const;

export interface Dish {
  name: string;
  description: string;
  price: number;
  tags: { tag: (typeof TAGS)[number] }[];
}

export interface FoodMenuProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  sections: { name: string; dishes: Dish[] }[];
  note: string;
}

export const FoodMenu: BlockDefinition<FoodMenuProps> = {
  type: 'FoodMenu',
  version: 1,
  label: 'Food menu',
  description: 'Your menu with prices and dietary tags.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    sections: {
      kind: 'list',
      label: 'Menu sections (e.g. Starters, Mains)',
      max: 12,
      item: {
        name: { kind: 'text', inline: true, label: 'Section', max: 40 },
        dishes: {
          kind: 'list',
          label: 'Dishes',
          max: 30,
          item: {
            name: { kind: 'text', inline: true, label: 'Dish', max: 60 },
            description: { kind: 'text', inline: true, label: 'Description', max: 160 },
            price: { kind: 'money', label: 'Price' },
            tags: {
              kind: 'list',
              label: 'Dietary tags',
              max: 4,
              item: {
                tag: {
                  kind: 'select',
                  label: 'Tag',
                  options: TAGS.map((t) => ({ value: t, label: t })),
                },
              },
            },
          },
        },
      },
    },
    note: { kind: 'text', inline: true, label: 'Note (e.g. allergies, service charge)', max: 200 },
  },
  defaults: { heading: 'Menu', intro: '', sections: [], note: '' },
  render: (p, site) => (
    <Section narrow>
      <Heading intro={p.intro}>{p.heading}</Heading>
      {p.sections.length === 0 ? (
        <div className="mt-8">
          <EmptyHint editing={site.editing}>Add a menu section and its dishes.</EmptyHint>
        </div>
      ) : (
        <div className="mt-10 grid gap-12">
          {p.sections.map((section, i) => (
            <section
              key={i}
              aria-label={typeof section.name === 'string' ? section.name : undefined}
            >
              <h3 className="border-b border-[var(--s-border)] pb-3 font-[family-name:var(--s-font-display)] text-2xl font-bold">
                {section.name}
              </h3>
              <ul className="mt-6 grid gap-6">
                {section.dishes.map((dish, j) => (
                  <li key={j}>
                    <div className="flex items-baseline gap-3">
                      <span className="font-semibold">{dish.name}</span>
                      <span
                        aria-hidden
                        className="min-w-6 flex-1 translate-y-[-0.3em] border-b-2 border-dotted border-[var(--s-border)]"
                      />
                      <span className="shrink-0 font-semibold text-[var(--s-brand-text)] tabular-nums">
                        {dish.price > 0 ? rand(dish.price) : ''}
                      </span>
                    </div>
                    {dish.description && (
                      <p className="mt-1 text-[15px] text-[var(--s-fg-muted)]">
                        {dish.description}
                      </p>
                    )}
                    {dish.tags.length > 0 && (
                      <ul className="mt-2 flex flex-wrap gap-1.5" aria-label="Dietary information">
                        {dish.tags.map((t, k) => (
                          <li
                            key={k}
                            className="rounded-full bg-[var(--s-brand-softer)] px-2.5 py-0.5 text-xs font-medium text-[var(--s-brand-text)]"
                          >
                            {t.tag}
                          </li>
                        ))}
                      </ul>
                    )}
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
      {p.note && (
        <p className="mt-10 border-t border-[var(--s-border)] pt-5 text-sm text-[var(--s-fg-muted)]">
          {p.note}
        </p>
      )}
    </Section>
  ),
};
