import { MapPin } from 'lucide-react';
import type { BlockDefinition } from '../types';
import { EmptyHint, Heading, Section } from './shared';

export interface ServiceAreasProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  areas: { name: string }[];
  note: string;
  mapQuery: string;
}

export const ServiceAreas: BlockDefinition<ServiceAreasProps> = {
  type: 'ServiceAreas',
  version: 1,
  label: 'Service areas',
  description: 'Where you work — suburbs and towns you cover.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    areas: {
      kind: 'list',
      label: 'Areas',
      max: 40,
      item: { name: { kind: 'text', inline: true, label: 'Area', max: 40 } },
    },
    note: {
      kind: 'text',
      inline: true,
      label: 'Note (e.g. call-out fee outside these areas)',
      max: 160,
    },
    mapQuery: {
      kind: 'text',
      label: 'Map link — area to show (optional, e.g. Durban North)',
      max: 80,
    },
  },
  isEmpty: (p) => p.areas.length === 0,
  defaults: { heading: 'Areas we cover', intro: '', areas: [], note: '', mapQuery: '' },
  render: (p, site) => (
    <Section narrow>
      <Heading intro={p.intro}>{p.heading}</Heading>
      {p.areas.length === 0 ? (
        <div className="mt-8">
          <EmptyHint editing={site.editing}>Add the suburbs and towns you work in.</EmptyHint>
        </div>
      ) : (
        <ul className="mt-8 flex flex-wrap gap-2" aria-label="Areas we cover">
          {p.areas.map((a, i) => (
            <li
              key={i}
              className="inline-flex items-center gap-1.5 rounded-full bg-[var(--s-brand-softer)] px-3.5 py-2 font-medium text-[var(--s-brand-text)]"
            >
              <MapPin className="size-4" aria-hidden />
              {a.name}
            </li>
          ))}
        </ul>
      )}
      {p.note && <p className="mt-6 text-[var(--s-fg-muted)]">{p.note}</p>}
      {/* A link out, never an embedded map: no third-party requests from the site. */}
      {p.mapQuery && (
        <a
          href={
            site.editing
              ? undefined
              : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(p.mapQuery)}`
          }
          target="_blank"
          rel="noopener"
          className="mt-6 inline-flex font-semibold text-[var(--s-brand-text)] underline-offset-4 hover:underline"
        >
          View on map<span className="sr-only"> (opens in a new tab)</span>
        </a>
      )}
    </Section>
  ),
};
