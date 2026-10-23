import {
  BadgeCheck,
  CalendarCheck,
  Clock,
  Heart,
  MapPin,
  ShieldCheck,
  Sparkles,
  Star,
  Users,
} from 'lucide-react';
import type { BlockDefinition } from '../types';
import { EmptyHint, Heading, Section } from './shared';

const ICONS = {
  sparkles: Sparkles,
  heart: Heart,
  shield: ShieldCheck,
  clock: Clock,
  calendar: CalendarCheck,
  star: Star,
  users: Users,
  pin: MapPin,
  check: BadgeCheck,
} as const;
type IconName = keyof typeof ICONS;

export interface FeatureGridProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  items: { icon: IconName; title: string; text: string }[];
}

export const FeatureGrid: BlockDefinition<FeatureGridProps> = {
  type: 'FeatureGrid',
  version: 1,
  label: 'Why choose us',
  description: 'Three to six reasons customers pick you, each with an icon.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', multiline: true, max: 200 },
    items: {
      kind: 'list',
      label: 'Reasons',
      max: 6,
      item: {
        icon: {
          kind: 'select',
          label: 'Icon',
          options: (Object.keys(ICONS) as IconName[]).map((k) => ({ value: k, label: k })),
        },
        title: { kind: 'text', inline: true, label: 'Title', max: 40 },
        text: { kind: 'text', inline: true, label: 'Text', multiline: true, max: 160 },
      },
    },
  },
  defaults: { heading: 'Why customers choose us', intro: '', items: [] },
  render: (p, site) => (
    <Section>
      <Heading intro={p.intro}>{p.heading}</Heading>
      {p.items.length === 0 ? (
        <div className="mt-10">
          <EmptyHint editing={site.editing}>
            Add three to six reasons customers choose you.
          </EmptyHint>
        </div>
      ) : (
        <ul className="mt-12 grid gap-x-10 gap-y-12 sm:grid-cols-2 lg:grid-cols-3">
          {p.items.map((item, i) => {
            const Icon = ICONS[item.icon] ?? Sparkles;
            return (
              <li key={i}>
                <span className="grid size-12 place-items-center rounded-[var(--s-radius)] bg-[var(--s-brand-soft)] text-[var(--s-brand-text)]">
                  <Icon className="size-6" aria-hidden />
                </span>
                <h3 className="mt-5 text-lg font-semibold">{item.title}</h3>
                <p className="mt-2 leading-relaxed text-[var(--s-fg-muted)]">{item.text}</p>
              </li>
            );
          })}
        </ul>
      )}
    </Section>
  ),
};
