import '../site/fonts';
import { Head } from '@inertiajs/react';
import { SiteRenderer } from '../site/SiteRenderer';
import type { Branding, ContentDocument, MenuItem } from '../site/types';

/* Sample businesses for the design board — illustrative content and abstract stand-in images, not real customers. */
const img = (name: string) => `/images/board-standins/${name}.jpg`;
const week = (o: string, c: string, sat: [string, string], sun: [string, string] = ['', '']) => [
  ...Array.from({ length: 5 }, () => ({ opens: o, closes: c })),
  { opens: sat[0], closes: sat[1] },
  { opens: sun[0], closes: sun[1] },
];

const SAMPLES: Record<
  'salon' | 'dental' | 'sections',
  {
    name: string;
    branding: Branding;
    menus: { header: MenuItem[]; footer: MenuItem[] };
    document: ContentDocument;
  }
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
    menus: {
      header: [
        { label: 'Prices', href: '#prices' },
        { label: 'Our work', href: '#work' },
        { label: 'Team', href: '#team' },
        { label: 'Contact', href: '#contact' },
      ],
      footer: [
        { label: 'Booking policy', href: '#' },
        { label: 'Privacy', href: '#' },
      ],
    },
    document: {
      schema: 1,
      blocks: [
        {
          id: 'header',
          type: 'Header',
          version: 1,
          props: { buttonLabel: 'Book now', sticky: true },
        },
        {
          id: 'hero',
          type: 'Hero',
          version: 2,
          props: {
            layout: 'photo',
            heading: 'Braids, locs and colour that last — in the heart of Soweto',
            text: 'Book your stylist online in a minute. We’ll send a WhatsApp reminder the day before.',
            primaryLabel: 'Book an appointment',
            secondaryLabel: 'See prices',
            facts: [
              { text: 'Open today until 18:00' },
              { text: 'Vilakazi Street, Orlando West' },
              { text: 'Card or cash' },
            ],
            images: [{ src: img('salon-hero'), alt: 'Inside Glow Salon' }],
          },
        },
        {
          id: 'why',
          type: 'FeatureGrid',
          version: 1,
          props: {
            heading: 'Why clients come back',
            intro: '',
            items: [
              {
                icon: 'clock',
                title: 'On time, every time',
                text: 'Your slot is yours. Most appointments start within five minutes.',
              },
              {
                icon: 'heart',
                title: 'Kind to your hair',
                text: 'Low-tension braiding and treatments chosen for your hair type.',
              },
              {
                icon: 'calendar',
                title: 'Book any time',
                text: 'Choose your stylist and time online, even at midnight.',
              },
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
            ],
            note: 'A R100 deposit secures your booking and comes off your bill.',
          },
        },
        {
          id: 'work',
          type: 'Gallery',
          version: 1,
          props: {
            heading: 'Our work',
            intro: '',
            images: [1, 2, 3, 1, 2].map((n, i) => ({
              src: img(`salon-${n}`),
              alt: `Hairstyle ${i + 1}`,
            })),
          },
        },
        {
          id: 'story',
          type: 'ImageText',
          version: 1,
          props: {
            heading: 'A salon built by the neighbourhood',
            imageSide: 'left',
            image: img('salon-2'),
            imageAlt: 'Lerato at work',
            text: 'Lerato opened Glow in 2016 with one chair. Ten years later there are six stylists, and many clients still book with her personally.\n\nWe train our own stylists and pay them fairly — that’s why they stay, and why your braids look the same every visit.',
            buttonLabel: 'Meet the team',
            buttonHref: '#team',
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
                  'Lerato did my knotless braids in under four hours and they still look fresh three weeks later.',
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
            ],
          },
        },
        {
          id: 'team',
          type: 'Team',
          version: 1,
          props: {
            heading: 'Meet the team',
            intro: '',
            people: [
              {
                name: 'Lerato Dube',
                role: 'Founder · braids and locs',
                bio: '',
                photo: img('team-1'),
              },
              { name: 'Thabo Nkosi', role: 'Colour specialist', bio: '', photo: img('team-2') },
              {
                name: 'Ayanda Zulu',
                role: 'Silk press and treatments',
                bio: '',
                photo: img('team-3'),
              },
              { name: 'Sipho Mahlangu', role: 'Junior stylist', bio: '', photo: '' },
            ],
          },
        },
        {
          id: 'hours',
          type: 'OpeningHours',
          version: 1,
          props: {
            heading: 'Opening hours',
            hours: week('09:00', '18:00', ['08:00', '16:00']),
            note: 'Closed on public holidays.',
          },
        },
        {
          id: 'faq',
          type: 'Faq',
          version: 1,
          props: {
            heading: 'Questions',
            items: [
              {
                question: 'Do I need to bring my own hair?',
                answer: 'You can, or buy it here — we stock most colours and lengths.',
              },
              {
                question: 'How does the deposit work?',
                answer:
                  'R100 secures your booking and comes off your bill. Cancel at least 24 hours before to get it back.',
              },
            ],
          },
        },
        {
          id: 'contact',
          type: 'Contact',
          version: 1,
          props: {
            heading: 'Visit or get in touch',
            intro: '',
            address: '1234 Vilakazi Street\nOrlando West, Soweto',
            phone: '011 123 4567',
            whatsapp: '082 123 4567',
            email: '',
          },
        },
        {
          id: 'cta',
          type: 'CallToAction',
          version: 1,
          props: {
            heading: 'Your next appointment is a minute away',
            text: 'Pick your stylist and time online.',
            buttonLabel: 'Book now',
          },
        },
        {
          id: 'footer',
          type: 'Footer',
          version: 1,
          props: { text: 'Braids, locs and colour in Orlando West since 2016.' },
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
    menus: {
      header: [
        { label: 'Treatments', href: '#prices' },
        { label: 'Our practice', href: '#story' },
        { label: 'Contact', href: '#contact' },
      ],
      footer: [{ label: 'Privacy (POPIA)', href: '#' }],
    },
    document: {
      schema: 1,
      blocks: [
        {
          id: 'header',
          type: 'Header',
          version: 1,
          props: { buttonLabel: 'Book a check-up', sticky: true },
        },
        {
          id: 'hero',
          type: 'Hero',
          version: 2,
          props: {
            layout: 'collage',
            heading: 'Gentle dentistry for the whole family in Durban North',
            text: 'Same-week appointments, medical aid claims done for you, and a team that explains everything first.',
            primaryLabel: 'Book a check-up',
            secondaryLabel: 'Our treatments',
            facts: [
              { text: 'Mon–Sat, 08:00–17:00' },
              { text: 'Medical aid accepted' },
              { text: 'Free parking' },
            ],
            images: [1, 2, 3].map((n) => ({
              src: img(`dental-${n}`),
              alt: `Bright Smiles practice ${n}`,
            })),
          },
        },
        {
          id: 'prices',
          type: 'ServicesPrices',
          version: 1,
          props: {
            heading: 'Treatments',
            intro:
              'Private rates. Medical aid members usually pay less — we’ll check your cover first.',
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
            ],
            note: '',
          },
        },
        {
          id: 'story',
          type: 'ImageText',
          version: 1,
          props: {
            heading: 'No surprises, ever',
            imageSide: 'right',
            image: img('dental-1'),
            imageAlt: 'Treatment room',
            text: 'Before any treatment, we show you what we see and what it will cost. You decide — no pressure.',
            buttonLabel: '',
            buttonHref: '',
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
                  'I’ve been scared of dentists all my life. Dr Pillay talked me through every step and it didn’t hurt once.',
                name: 'Priya N.',
                detail: 'Patient since 2019',
                rating: 5,
              },
            ],
          },
        },
        {
          id: 'hours',
          type: 'OpeningHours',
          version: 1,
          props: {
            heading: 'Practice hours',
            hours: week('08:00', '17:00', ['08:00', '13:00']),
            note: 'Emergencies: WhatsApp us and we’ll fit you in.',
          },
        },
        {
          id: 'contact',
          type: 'Contact',
          version: 1,
          props: {
            heading: 'Find us',
            intro: '',
            address: '45 Adelaide Tambo Drive\nDurban North',
            phone: '031 123 4567',
            whatsapp: '',
            email: 'hello@brightsmiles.co.za',
          },
        },
        {
          id: 'footer',
          type: 'Footer',
          version: 1,
          props: { text: 'Family dentistry in Durban North.' },
        },
      ],
    },
  },
  sections: {
    name: 'Sections sample',
    branding: {
      primary: '#166534',
      secondary: '#F59E0B',
      font_pairing: 'technical',
      corners: 'square',
      logo: null,
      favicon: null,
    },
    menus: { header: [{ label: 'Quote', href: '#quote' }], footer: [] },
    document: {
      schema: 1,
      blocks: [
        {
          id: 'promo',
          type: 'PromotionBanner',
          version: 1,
          props: {
            text: 'Load-shedding special: free site assessment in December',
            linkLabel: 'Book yours',
            linkHref: '#quote',
            tone: 'accent',
            starts: '',
            ends: '',
          },
        },
        {
          id: 'header',
          type: 'Header',
          version: 1,
          props: { buttonLabel: 'Get a quote', buttonHref: '#quote' },
        },
        {
          id: 'ba',
          type: 'BeforeAfter',
          version: 1,
          props: {
            heading: 'Before & after',
            intro: 'Real installations by our team.',
            layout: 'slider',
            items: [
              {
                before: img('dental-1'),
                beforeAlt: 'Roof before installation',
                after: img('dental-2'),
                afterAlt: 'Roof with solar panels installed',
                caption: '5 kW system, Umhlanga',
              },
              {
                before: img('salon-1'),
                beforeAlt: 'Old distribution board',
                after: img('salon-2'),
                afterAlt: 'New distribution board',
                caption: 'DB board upgrade, Durban North',
              },
            ],
          },
        },
        {
          id: 'pk',
          type: 'Packages',
          version: 1,
          props: {
            heading: 'Backup power packages',
            intro: 'Fixed prices, installed and certified.',
            items: [
              {
                name: 'Essentials',
                price: 2490000,
                from: true,
                inclusions: '3 kW inverter\n5 kWh battery\nCertificate of compliance',
                validUntil: '',
                highlight: false,
                buttonLabel: 'Choose Essentials',
                buttonHref: '#quote',
              },
              {
                name: 'Whole home',
                price: 6990000,
                from: true,
                inclusions: '8 kW inverter\n10 kWh battery\n6 solar panels\nApp monitoring',
                validUntil: '',
                highlight: true,
                buttonLabel: 'Choose Whole home',
                buttonHref: '#quote',
              },
            ],
          },
        },
        {
          id: 'tt',
          type: 'ClassTimetable',
          version: 1,
          props: {
            heading: 'Class timetable',
            intro: 'Gym sample — all classes 45 minutes.',
            classes: [
              {
                day: 'Monday',
                start: '06:00',
                end: '06:45',
                name: 'HIIT',
                instructor: 'Thabo',
                level: 'All levels',
              },
              {
                day: 'Monday',
                start: '18:00',
                end: '18:45',
                name: 'Spin',
                instructor: 'Aisha',
                level: 'Intermediate',
              },
              {
                day: 'Wednesday',
                start: '06:00',
                end: '06:45',
                name: 'Strength',
                instructor: 'Thabo',
                level: 'Beginner',
              },
              {
                day: 'Saturday',
                start: '08:00',
                end: '09:00',
                name: 'Yoga',
                instructor: 'Naledi',
                level: 'All levels',
              },
            ],
            note: 'Public holidays: Saturday times.',
          },
        },
        {
          id: 'fm',
          type: 'FoodMenu',
          version: 1,
          props: {
            heading: 'Menu',
            intro: 'Restaurant sample.',
            sections: [
              {
                name: 'Mains',
                dishes: [
                  {
                    name: 'Bunny chow',
                    description: 'Quarter loaf, mutton curry',
                    price: 12500,
                    tags: [{ tag: 'Halaal' }, { tag: 'Spicy' }],
                  },
                  {
                    name: 'Bobotie',
                    description: 'With yellow rice and chutney',
                    price: 14500,
                    tags: [],
                  },
                  {
                    name: 'Chakalaka bowl',
                    description: 'Pap, chakalaka, grilled veg',
                    price: 9800,
                    tags: [{ tag: 'Vegan' }],
                  },
                ],
              },
            ],
            note: 'Please tell us about any allergies.',
          },
        },
        {
          id: 'rr',
          type: 'RoomsRates',
          version: 1,
          props: {
            heading: 'Rooms & rates',
            intro: 'Guesthouse sample.',
            rooms: [
              {
                name: 'Garden room',
                photo: img('salon-3'),
                photoAlt: 'Bedroom opening onto the garden',
                sleeps: '2 adults',
                price: 115000,
                description: 'Quiet room with a private patio.',
                features: 'Breakfast included\nFree Wi-Fi\nBackup power',
              },
              {
                name: 'Family suite',
                photo: img('dental-3'),
                photoAlt: 'Family suite with two beds',
                sleeps: '2 adults, 2 children',
                price: 189000,
                description: 'Two bedrooms and a lounge.',
                features: 'Breakfast included\nKitchenette',
              },
            ],
            buttonLabel: 'Check availability',
            buttonHref: '#quote',
            note: 'Check-in from 14:00.',
          },
        },
        {
          id: 'pf',
          type: 'Portfolio',
          version: 1,
          props: {
            heading: 'Recent projects',
            intro: '',
            projects: [
              {
                title: 'Off-grid farmhouse',
                photo: img('team-1'),
                photoAlt: 'Farmhouse with a solar array',
                category: 'Solar',
                story: 'A farm outside Pietermaritzburg wanted to stop relying on the grid.',
                outcome: 'No outages since installation.',
              },
              {
                title: 'Clinic backup',
                photo: img('team-2'),
                photoAlt: 'Inverters installed in a clinic',
                category: 'Backup power',
                story: 'A medical practice needed its fridges and lights to stay on.',
                outcome: 'Runs for 9 hours on battery.',
              },
            ],
          },
        },
        {
          id: 'ac',
          type: 'Accreditations',
          version: 1,
          props: {
            heading: 'Registered and accredited',
            intro: '',
            items: [
              { name: 'ECB registered', logo: '', detail: 'Electrical contractor' },
              { name: 'PV GreenCard', logo: '', detail: 'Certified installer' },
              { name: 'SAPVIA member', logo: '', detail: '' },
            ],
          },
        },
        {
          id: 'sa',
          type: 'ServiceAreas',
          version: 1,
          props: {
            heading: 'Areas we cover',
            intro: 'Same-week installations in:',
            areas: [
              { name: 'Durban North' },
              { name: 'Umhlanga' },
              { name: 'Ballito' },
              { name: 'Westville' },
              { name: 'Pinetown' },
            ],
            note: 'Further afield? Ask about our call-out fee.',
            mapQuery: 'Durban North',
          },
        },
        {
          id: 'qf',
          type: 'QuoteForm',
          version: 1,
          props: {
            heading: 'Request a quote',
            intro: 'Tell us what you need and we’ll get back to you, usually within a day.',
            services: [
              { name: 'Solar installation' },
              { name: 'Backup power' },
              { name: 'Electrical repair' },
            ],
            detailsLabel: 'Tell us about the job',
            submitLabel: 'Send request',
          },
        },
        {
          id: 'footer',
          type: 'Footer',
          version: 1,
          props: { text: 'Sample content for the design board.' },
        },
      ],
    },
  },
};

export default function BlockBoard({ business }: { business: 'salon' | 'dental' | 'sections' }) {
  const sample = SAMPLES[business];
  return (
    <>
      <Head title={`Block board — ${sample.name}`} />
      <p className="bg-[#1B1640] px-5 py-2 text-center text-xs font-medium text-white">
        Design board · sample business, content and abstract stand-in photos · not a real customer
      </p>
      <SiteRenderer
        document={sample.document}
        branding={sample.branding}
        site={{ businessName: sample.name, editing: false, menus: sample.menus }}
      />
    </>
  );
}
