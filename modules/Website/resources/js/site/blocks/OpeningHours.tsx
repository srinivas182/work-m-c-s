import type { BlockDefinition } from '../types';
import { Heading, Section } from './shared';

const DAYS = [
  'Monday',
  'Tuesday',
  'Wednesday',
  'Thursday',
  'Friday',
  'Saturday',
  'Sunday',
] as const;

export interface OpeningHoursProps extends Record<string, unknown> {
  heading: string;
  /** One row per day, Monday first; empty opens = closed. */
  hours: { opens: string; closes: string }[];
  note: string;
}

export const OpeningHours: BlockDefinition<OpeningHoursProps> = {
  type: 'OpeningHours',
  version: 1,
  label: 'Opening hours',
  description: 'When you’re open, day by day, with a note for public holidays.',
  fields: {
    heading: { kind: 'text', label: 'Heading', max: 60 },
    hours: {
      kind: 'list',
      label: 'Hours (Monday to Sunday)',
      max: 7,
      item: {
        opens: { kind: 'text', label: 'Opens (e.g. 08:00)', max: 5 },
        closes: { kind: 'text', label: 'Closes (e.g. 17:00)', max: 5 },
      },
    },
    note: { kind: 'text', label: 'Note (e.g. public holidays)', max: 160 },
  },
  defaults: {
    heading: 'Opening hours',
    hours: [
      ...Array(5).fill({ opens: '08:00', closes: '17:00' }),
      { opens: '08:00', closes: '13:00' },
      { opens: '', closes: '' },
    ],
    note: 'Closed on public holidays.',
  },
  render: (p) => (
    <Section tone="surface" narrow>
      <Heading>{p.heading}</Heading>
      <dl className="mt-8 grid divide-y divide-[var(--s-border)] border-y border-[var(--s-border)]">
        {DAYS.map((day, i) => {
          const h = p.hours[i];
          const open = h && h.opens !== '' && h.closes !== '';
          return (
            <div key={day} className="flex items-center justify-between py-3.5">
              <dt className="font-medium">{day}</dt>
              <dd className={`tabular-nums ${open ? '' : 'text-[var(--s-fg-muted)]'}`}>
                {open ? `${h.opens} – ${h.closes}` : 'Closed'}
              </dd>
            </div>
          );
        })}
      </dl>
      {p.note && <p className="mt-5 text-sm text-[var(--s-fg-muted)]">{p.note}</p>}
    </Section>
  ),
};
