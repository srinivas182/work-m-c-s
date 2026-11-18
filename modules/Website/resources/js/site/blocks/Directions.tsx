import { MapPin } from 'lucide-react';
import type { BlockDefinition } from '../types';
import { Heading, Section } from './shared';

export interface DirectionsProps extends Record<string, unknown> {
  heading: string;
  address: string;
  landmark: string;
  showMap: boolean;
}

/** Map and directions (Sprint 20.2): how to find you, with the map apps people already use. */
export const Directions: BlockDefinition<DirectionsProps> = {
  type: 'Directions',
  version: 1,
  label: 'Map and directions',
  description: 'Your address, a landmark, and buttons that open Google Maps, Waze or Apple Maps.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    address: {
      kind: 'text',
      label: 'Address (as you’d type it into a map)',
      multiline: true,
      max: 200,
    },
    landmark: { kind: 'text', inline: true, label: 'How to find us', multiline: true, max: 200 },
    showMap: {
      kind: 'select',
      label: 'Map on the page',
      options: [
        { value: true, label: 'Show a map when tapped' },
        { value: false, label: 'Buttons only' },
      ],
    },
  },
  isEmpty: (p) => p.address.trim() === '',
  defaults: { heading: 'Find us', address: '', landmark: '', showMap: true },
  render: (p, site) => {
    const q = encodeURIComponent(p.address.replace(/\s+/g, ' ').trim());
    const apps: [string, string][] = [
      ['Google Maps', `https://www.google.com/maps/search/?api=1&query=${q}`],
      ['Waze', `https://waze.com/ul?q=${q}&navigate=yes`],
      ['Apple Maps', `https://maps.apple.com/?q=${q}`],
    ];
    return (
      <Section>
        <div className="grid gap-10 lg:grid-cols-2 lg:items-start">
          <div>
            <Heading>{p.heading}</Heading>
            {p.address ? (
              <address className="mt-6 flex gap-3 text-lg not-italic">
                <MapPin className="mt-1 size-5 shrink-0 text-[var(--s-brand-text)]" aria-hidden />
                <span className="whitespace-pre-line">{p.address}</span>
              </address>
            ) : (
              site.editing && <p className="mt-6 text-[var(--s-fg-muted)]">Add your address.</p>
            )}
            {p.landmark && (
              <p className="mt-4 leading-relaxed text-[var(--s-fg-muted)]">{p.landmark}</p>
            )}
            {p.address && (
              <ul className="mt-8 flex flex-wrap gap-3">
                {apps.map(([name, href]) => (
                  <li key={name}>
                    <a
                      href={site.editing ? undefined : href}
                      target="_blank"
                      rel="noopener"
                      className="inline-flex min-h-11 items-center rounded-[var(--s-radius)] border border-[var(--s-border)] px-4 font-semibold hover:border-[var(--s-brand)]"
                    >
                      {name}
                      <span className="sr-only"> (opens in a new tab)</span>
                    </a>
                  </li>
                ))}
              </ul>
            )}
          </div>
          {p.address && p.showMap && (
            // Nothing from a map provider loads until the visitor asks: no tracking, and no data spent (site-islands.ts).
            <div
              data-island="map"
              data-query={p.address.replace(/\s+/g, ' ').trim()}
              className="grid aspect-[4/3] place-items-center rounded-[var(--s-radius)] border border-[var(--s-border)] bg-[var(--s-surface-2)]"
            >
              <button
                type="button"
                data-map-show
                className="s-btn inline-flex min-h-11 items-center gap-2 rounded-[var(--s-radius)] bg-[var(--s-brand)] px-5 font-semibold text-[var(--s-on-brand)]"
              >
                <MapPin className="size-4" aria-hidden />
                Show the map
              </button>
            </div>
          )}
        </div>
      </Section>
    );
  },
};
