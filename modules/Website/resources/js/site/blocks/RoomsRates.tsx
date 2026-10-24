import { BedDouble, Check } from 'lucide-react';
import { rand } from '@/lib/money';
import type { BlockDefinition } from '../types';
import { EmptyHint, Heading, PhotoPrompt, photoSrcSet, Section } from './shared';

export interface Room {
  name: string;
  photo: string;
  photoAlt: string;
  sleeps: string;
  price: number;
  description: string;
  features: string;
}

export interface RoomsRatesProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  rooms: Room[];
  buttonLabel: string;
  buttonHref: string;
  note: string;
}

/**
 * Rooms and nightly rates. "Check availability" links to the enquiry form or the
 * guesthouse's own booking engine — opened as a link, never embedded, so visitors'
 * browsers make no third-party requests (POPIA).
 */
export const RoomsRates: BlockDefinition<RoomsRatesProps> = {
  type: 'RoomsRates',
  version: 1,
  label: 'Rooms & rates',
  description: 'Your rooms, who they sleep and the price per night.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    rooms: {
      kind: 'list',
      label: 'Rooms',
      max: 12,
      item: {
        name: { kind: 'text', inline: true, label: 'Room', max: 50 },
        photo: { kind: 'image', label: 'Photo' },
        photoAlt: { kind: 'text', label: 'Describe the photo', max: 140 },
        sleeps: { kind: 'text', inline: true, label: 'Sleeps (e.g. 2 adults)', max: 40 },
        price: { kind: 'money', label: 'From, per night' },
        description: {
          kind: 'text',
          inline: true,
          label: 'Description',
          multiline: true,
          max: 300,
        },
        features: { kind: 'text', label: 'Features (one per line)', multiline: true, max: 300 },
      },
    },
    buttonLabel: { kind: 'text', inline: true, label: 'Button', max: 30 },
    buttonHref: { kind: 'link', label: 'Button goes to (enquiry form or your booking engine)' },
    note: {
      kind: 'text',
      inline: true,
      label: 'Note (e.g. breakfast included, check-in 14:00)',
      max: 200,
    },
  },
  isEmpty: (p) => p.rooms.length === 0,
  defaults: {
    heading: 'Rooms & rates',
    intro: '',
    rooms: [],
    buttonLabel: 'Check availability',
    buttonHref: '#contact',
    note: '',
  },
  render: (p, site) => (
    <Section>
      <Heading intro={p.intro}>{p.heading}</Heading>
      {p.rooms.length === 0 ? (
        <div className="mt-8">
          <EmptyHint editing={site.editing}>Add your rooms.</EmptyHint>
        </div>
      ) : (
        <div className="mt-10 grid gap-8 md:grid-cols-2 lg:grid-cols-3">
          {p.rooms.map((room, i) => {
            const features =
              typeof room.features === 'string'
                ? room.features
                    .split('\n')
                    .map((f) => f.trim())
                    .filter(Boolean)
                : [];
            return (
              <article
                key={i}
                className="flex flex-col overflow-hidden rounded-[var(--s-radius)] border border-[var(--s-border)]"
              >
                {room.photo ? (
                  <img
                    src={room.photo}
                    srcSet={photoSrcSet(room.photo)}
                    sizes="(min-width: 1024px) 33vw, (min-width: 768px) 50vw, 100vw"
                    alt={room.photoAlt}
                    loading="lazy"
                    decoding="async"
                    className="aspect-[4/3] w-full object-cover"
                  />
                ) : (
                  <PhotoPrompt
                    editing={site.editing}
                    className="aspect-[4/3] w-full rounded-none border-0 border-b-2"
                    text="Add a photo of the room"
                  />
                )}
                <div className="flex flex-1 flex-col gap-3 p-6">
                  <h3 className="font-[family-name:var(--s-font-display)] text-xl font-bold">
                    {room.name}
                  </h3>
                  {room.sleeps && (
                    <p className="flex items-center gap-1.5 text-sm text-[var(--s-fg-muted)]">
                      <BedDouble className="size-4" aria-hidden /> Sleeps {room.sleeps}
                    </p>
                  )}
                  {room.description && (
                    <p className="text-[var(--s-fg-muted)]">{room.description}</p>
                  )}
                  {features.length > 0 && (
                    <ul className="grid gap-1.5 text-sm">
                      {features.map((f, j) => (
                        <li key={j} className="flex gap-2">
                          <Check className="size-4 shrink-0 text-[var(--s-brand)]" aria-hidden />
                          {f}
                        </li>
                      ))}
                    </ul>
                  )}
                  <p className="mt-auto pt-2 text-lg font-bold text-[var(--s-brand-text)]">
                    {room.price > 0 ? `from ${rand(room.price)} / night` : 'Ask for rates'}
                  </p>
                </div>
              </article>
            );
          })}
        </div>
      )}
      {(p.buttonLabel || p.note) && (
        <div className="mt-10 flex flex-wrap items-center gap-x-6 gap-y-3">
          {p.buttonLabel && (
            <a
              href={site.editing ? undefined : p.buttonHref}
              className="inline-flex min-h-12 items-center rounded-[var(--s-radius)] bg-[var(--s-brand)] px-6 font-semibold text-[var(--s-on-brand)]"
            >
              {p.buttonLabel}
            </a>
          )}
          {p.note && <p className="text-sm text-[var(--s-fg-muted)]">{p.note}</p>}
        </div>
      )}
    </Section>
  ),
};
