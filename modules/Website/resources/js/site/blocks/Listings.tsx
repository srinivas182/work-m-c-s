import { Bath, BedDouble, Car, MapPin, Ruler } from 'lucide-react';
import { rand } from '@/lib/money';
import type { BlockDefinition } from '../types';
import { EmptyHint, Heading, PhotoPrompt, photoSrcSet, Section } from './shared';

export type ListingStatus = 'available' | 'under-offer' | 'sold' | 'let';

export interface Listing {
  title: string;
  photo: string;
  photoAlt: string;
  deal: 'sale' | 'rent';
  price: number;
  area: string;
  beds: string;
  baths: string;
  parking: string;
  size: string;
  status: ListingStatus;
  detailsHref: string;
}

export interface ListingsProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  listings: Listing[];
  buttonLabel: string;
  buttonHref: string;
}

const STATUS: Record<ListingStatus, string> = {
  available: 'Available',
  'under-offer': 'Under offer',
  sold: 'Sold',
  let: 'Let',
};

/**
 * Property listings (Sprint 20.4d): homes for sale or to rent, with the facts buyers compare first — price, area,
 * bedrooms, bathrooms, parking and size. Each can link to a viewing form or the listing on a portal (as a link only).
 */
export const Listings: BlockDefinition<ListingsProps> = {
  type: 'Listings',
  version: 1,
  label: 'Property listings',
  description: 'Homes for sale or to rent, with price, bedrooms, bathrooms and size.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    listings: {
      kind: 'list',
      label: 'Listings',
      max: 24,
      item: {
        title: { kind: 'text', label: 'Title (e.g. 3-bedroom house with garden)', max: 80 },
        photo: { kind: 'image', label: 'Photo' },
        photoAlt: { kind: 'text', label: 'Describe the photo', max: 140 },
        deal: {
          kind: 'select',
          label: 'For',
          options: [
            { value: 'sale', label: 'Sale' },
            { value: 'rent', label: 'Rent (per month)' },
          ],
        },
        price: { kind: 'money', label: 'Price' },
        area: { kind: 'text', label: 'Suburb / area', max: 60 },
        beds: { kind: 'text', label: 'Bedrooms', max: 4 },
        baths: { kind: 'text', label: 'Bathrooms', max: 4 },
        parking: { kind: 'text', label: 'Parking', max: 4 },
        size: { kind: 'text', label: 'Size (m²)', max: 8 },
        status: {
          kind: 'select',
          label: 'Status',
          options: [
            { value: 'available', label: 'Available' },
            { value: 'under-offer', label: 'Under offer' },
            { value: 'sold', label: 'Sold' },
            { value: 'let', label: 'Let' },
          ],
        },
        detailsHref: { kind: 'link', label: 'More details (a page, form or portal link)' },
      },
    },
    buttonLabel: { kind: 'text', inline: true, label: 'Button', max: 30 },
    buttonHref: { kind: 'link', label: 'Button goes to' },
  },
  isEmpty: (p) => p.listings.length === 0,
  defaults: {
    heading: 'Properties',
    intro: '',
    listings: [],
    buttonLabel: '',
    buttonHref: '',
  },
  render: (p, site) => (
    <Section>
      <Heading intro={p.intro}>{p.heading}</Heading>
      {p.listings.length === 0 ? (
        <div className="mt-8">
          <EmptyHint editing={site.editing}>Add your properties.</EmptyHint>
        </div>
      ) : (
        <ul className="mt-10 grid gap-8 md:grid-cols-2 lg:grid-cols-3">
          {p.listings.map((l, i) => {
            const facts: [typeof BedDouble, string, string][] = [
              [BedDouble, l.beds, 'bedrooms'],
              [Bath, l.baths, 'bathrooms'],
              [Car, l.parking, 'parking'],
              [Ruler, l.size ? `${l.size} m²` : '', 'floor size'],
            ];
            const done = l.status === 'sold' || l.status === 'let';
            return (
              <li
                key={i}
                className="flex flex-col overflow-hidden rounded-[var(--s-radius)] border border-[var(--s-border)]"
              >
                <div className="relative">
                  {l.photo ? (
                    <img
                      src={l.photo}
                      srcSet={photoSrcSet(l.photo)}
                      sizes="(min-width: 1024px) 33vw, (min-width: 768px) 50vw, 100vw"
                      alt={l.photoAlt}
                      loading="lazy"
                      decoding="async"
                      className="aspect-[4/3] w-full object-cover"
                    />
                  ) : (
                    <PhotoPrompt
                      editing={site.editing}
                      className="aspect-[4/3] w-full rounded-none border-0 border-b-2"
                      text="Add a photo of the property"
                    />
                  )}
                  {l.status !== 'available' && (
                    <span
                      className={`absolute top-3 left-3 rounded-full px-3 py-1 text-xs font-bold ${done ? 'bg-black/80 text-white' : 'bg-[var(--s-brand)] text-[var(--s-on-brand)]'}`}
                    >
                      {STATUS[l.status] ?? ''}
                    </span>
                  )}
                </div>
                <div className="flex flex-1 flex-col gap-3 p-6">
                  <p className="text-xl font-bold text-[var(--s-brand-text)]">
                    {l.price > 0
                      ? `${rand(l.price)}${l.deal === 'rent' ? ' / month' : ''}`
                      : 'Price on request'}
                  </p>
                  <h3 className="font-[family-name:var(--s-font-display)] text-lg leading-snug font-bold">
                    {l.title}
                  </h3>
                  {l.area && (
                    <p className="flex items-center gap-1.5 text-sm text-[var(--s-fg-muted)]">
                      <MapPin className="size-4" aria-hidden /> {l.area}
                    </p>
                  )}
                  <ul className="flex flex-wrap gap-x-4 gap-y-1 text-sm" aria-label="Features">
                    {facts
                      .filter(([, v]) => v)
                      .map(([Icon, v, label]) => (
                        <li key={label} className="flex items-center gap-1.5">
                          <Icon className="size-4 text-[var(--s-fg-muted)]" aria-hidden />
                          <span>
                            {v}
                            <span className="sr-only">{` ${label}`}</span>
                          </span>
                        </li>
                      ))}
                  </ul>
                  {l.detailsHref && (
                    <a
                      href={site.editing ? undefined : l.detailsHref}
                      className="mt-auto pt-2 font-semibold text-[var(--s-brand-text)] underline-offset-4 hover:underline"
                    >
                      View details<span className="sr-only">{`: ${l.title}`}</span>
                    </a>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
      {p.buttonLabel && (
        <div className="mt-10">
          <a
            href={site.editing ? undefined : p.buttonHref}
            className="s-btn inline-flex min-h-12 items-center rounded-[var(--s-radius)] bg-[var(--s-brand)] px-6 font-semibold text-[var(--s-on-brand)]"
          >
            {p.buttonLabel}
          </a>
        </div>
      )}
    </Section>
  ),
};
