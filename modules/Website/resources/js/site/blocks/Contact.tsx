import { Mail, MapPin, MessageCircle, Phone } from 'lucide-react';
import type { BlockDefinition } from '../types';
import { Heading, Section } from './shared';

export interface ContactProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  address: string;
  phone: string;
  whatsapp: string;
  email: string;
}

/** South African numbers to international digits for tel: and WhatsApp links ("082…" → "2782…"). */
export function internationalDigits(phone: string): string {
  const d = phone.replace(/\D/g, '');
  return d.startsWith('0') ? `27${d.slice(1)}` : d;
}

/**
 * Ways to reach you. The map is a link to Google Maps rather than an embedded map:
 * pages stay fast and visitors' data isn't sent to a third party until they choose (POPIA).
 */
export const Contact: BlockDefinition<ContactProps> = {
  type: 'Contact',
  version: 1,
  label: 'Contact details',
  description: 'Address with a map link, tap-to-call, WhatsApp and email.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    address: { kind: 'text', label: 'Address', multiline: true, max: 200 },
    phone: { kind: 'text', label: 'Phone', max: 20 },
    whatsapp: { kind: 'text', label: 'WhatsApp number', max: 20 },
    email: { kind: 'text', label: 'Email', max: 120 },
  },
  defaults: {
    heading: 'Visit or get in touch',
    intro: '',
    address: '',
    phone: '',
    whatsapp: '',
    email: '',
  },
  render: (p, site) => {
    const href = (h: string) => (site.editing ? undefined : h);
    const rows = [
      p.address && {
        icon: MapPin,
        label: 'Address',
        value: p.address,
        link: `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(p.address)}`,
        action: 'Open in Google Maps',
      },
      p.phone && {
        icon: Phone,
        label: 'Phone',
        value: p.phone,
        link: `tel:+${internationalDigits(p.phone)}`,
        action: 'Call',
      },
      p.whatsapp && {
        icon: MessageCircle,
        label: 'WhatsApp',
        value: p.whatsapp,
        link: `https://wa.me/${internationalDigits(p.whatsapp)}`,
        action: 'Chat on WhatsApp',
      },
      p.email && {
        icon: Mail,
        label: 'Email',
        value: p.email,
        link: `mailto:${p.email}`,
        action: 'Email us',
      },
    ].filter(Boolean) as {
      icon: typeof MapPin;
      label: string;
      value: string;
      link: string;
      action: string;
    }[];

    return (
      <Section id="contact">
        <Heading intro={p.intro}>{p.heading}</Heading>
        <ul
          className={`mt-10 grid gap-4 sm:grid-cols-2 ${rows.length === 3 ? 'lg:grid-cols-3' : ''}`}
        >
          {rows.map((r) => (
            <li
              key={r.label}
              className="flex gap-4 rounded-[var(--s-radius)] border border-[var(--s-border)] p-6"
            >
              <span className="grid size-11 shrink-0 place-items-center rounded-full bg-[var(--s-brand-soft)] text-[var(--s-brand-text)]">
                <r.icon className="size-5" aria-hidden />
              </span>
              <div className="min-w-0">
                <p className="text-sm text-[var(--s-fg-muted)]">{r.label}</p>
                <p className="mt-0.5 font-medium break-words whitespace-pre-line">{r.value}</p>
                <a
                  href={href(r.link)}
                  className="mt-2 inline-flex min-h-11 items-center font-semibold text-[var(--s-brand-text)] underline-offset-4 hover:underline"
                  rel={r.link.startsWith('http') ? 'noopener' : undefined}
                >
                  {r.action}
                </a>
              </div>
            </li>
          ))}
          {rows.length === 0 && site.editing && (
            <li className="text-[var(--s-fg-muted)]">
              Add your address, phone, WhatsApp or email.
            </li>
          )}
        </ul>
      </Section>
    );
  },
};
