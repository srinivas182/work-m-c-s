import '@fontsource-variable/playfair-display';
import '@fontsource-variable/source-sans-3';
import { Head } from '@inertiajs/react';
import { SiteRenderer } from '../site/SiteRenderer';
import type { Branding, ContentDocument } from '../site/types';

/* Sample businesses for the design board — illustrative content, not real customers. */
const SAMPLES: Record<
  'salon' | 'dental',
  { name: string; branding: Branding; document: ContentDocument }
> = {
  salon: {
    name: 'Glow Salon',
    branding: {
      primary: '#9D174D',
      secondary: null,
      font_pairing: 'classic',
      corners: 'soft',
      logo: null,
      favicon: null,
    },
    document: {
      schema: 1,
      blocks: [
        {
          id: 'hero',
          type: 'Hero',
          version: 1,
          props: {
            layout: 'split',
            heading: 'Braids, locs and colour that last — in the heart of Soweto',
            text: 'Book your stylist online in a minute. We’ll send a WhatsApp reminder the day before, so you never lose your slot.',
            primaryLabel: 'Book an appointment',
            secondaryLabel: 'See prices',
            facts: [
              { text: 'Open today until 18:00' },
              { text: 'Vilakazi Street, Orlando West' },
              { text: 'Card or cash' },
            ],
          },
        },
        {
          id: 'prices',
          type: 'ServicesPrices',
          version: 1,
          props: {
            heading: 'Services and prices',
            intro: 'Prices include wash and blow-dry. Bring your own hair or buy it here.',
            items: [
              {
                name: 'Knotless braids',
                description: 'Mid-back length',
                duration: '4 hrs',
                price: 65000,
                from: true,
              },
              {
                name: 'Box braids',
                description: 'Shoulder length',
                duration: '3 hrs',
                price: 55000,
                from: false,
              },
              {
                name: 'Silk press',
                description: 'Heat protection included',
                duration: '1 hr 30',
                price: 38000,
                from: false,
              },
              {
                name: 'Colour',
                description: 'Consultation first',
                duration: '2 hrs',
                price: 45000,
                from: true,
              },
              {
                name: 'Wash and treatment',
                description: '',
                duration: '45 min',
                price: 22000,
                from: false,
              },
            ],
            note: 'A R100 deposit secures your booking and comes off your bill.',
          },
        },
        {
          id: 'reviews',
          type: 'Testimonials',
          version: 1,
          props: {
            heading: 'What our clients say',
            items: [
              {
                quote:
                  'Lerato did my knotless braids in under four hours and they still look fresh three weeks later. Booking online at night was so easy.',
                name: 'Naledi M.',
                detail: 'Client since 2021',
                rating: 5,
              },
              {
                quote: 'The WhatsApp reminder saved me — I’d have forgotten.',
                name: 'Zanele K.',
                detail: 'Orlando East',
                rating: 5,
              },
              {
                quote: 'Clean, friendly and on time. My go-to for a silk press.',
                name: 'Ayanda D.',
                detail: 'Client since 2023',
                rating: 4,
              },
            ],
          },
        },
      ],
    },
  },
  dental: {
    name: 'Bright Smiles Dental',
    branding: {
      primary: '#0F766E',
      secondary: null,
      font_pairing: 'modern',
      corners: 'round',
      logo: null,
      favicon: null,
    },
    document: {
      schema: 1,
      blocks: [
        {
          id: 'hero',
          type: 'Hero',
          version: 1,
          props: {
            layout: 'centered',
            heading: 'Gentle dentistry for the whole family in Durban North',
            text: 'Same-week appointments, medical aid claims done for you, and a team that explains everything first.',
            primaryLabel: 'Book a check-up',
            secondaryLabel: 'Our treatments',
            facts: [
              { text: 'Mon–Sat, 08:00–17:00' },
              { text: 'Medical aid accepted' },
              { text: 'Free parking' },
            ],
          },
        },
        {
          id: 'prices',
          type: 'ServicesPrices',
          version: 1,
          props: {
            heading: 'Treatments',
            intro:
              'Private rates. Medical aid members usually pay less — we’ll check your cover before treatment.',
            items: [
              {
                name: 'Check-up and clean',
                description: 'Includes X-rays if needed',
                duration: '45 min',
                price: 65000,
                from: false,
              },
              {
                name: 'Fillings',
                description: 'Tooth-coloured',
                duration: '30–60 min',
                price: 85000,
                from: true,
              },
              {
                name: 'Teeth whitening',
                description: 'In-chair, one visit',
                duration: '90 min',
                price: 290000,
                from: true,
              },
              {
                name: 'Emergency appointment',
                description: 'Same day when possible',
                duration: '',
                price: 95000,
                from: false,
              },
            ],
            note: '',
          },
        },
        {
          id: 'reviews',
          type: 'Testimonials',
          version: 1,
          props: {
            heading: 'Patients say',
            items: [
              {
                quote:
                  'I’ve been scared of dentists all my life. Dr Pillay talked me through every step and it didn’t hurt once. My children go here now too.',
                name: 'Priya N.',
                detail: 'Patient since 2019',
                rating: 5,
              },
            ],
          },
        },
      ],
    },
  },
};

export default function BlockBoard({ business }: { business: 'salon' | 'dental' }) {
  const sample = SAMPLES[business];
  return (
    <>
      <Head title={`Block board — ${sample.name}`} />
      <p className="bg-[#1B1640] px-5 py-2 text-center text-xs font-medium text-white">
        Design board · sample business and content · not a real customer
      </p>
      <main>
        <SiteRenderer
          document={sample.document}
          branding={sample.branding}
          site={{ businessName: sample.name, editing: false }}
        />
      </main>
    </>
  );
}
