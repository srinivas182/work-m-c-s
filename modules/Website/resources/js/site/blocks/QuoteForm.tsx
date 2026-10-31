import { useId } from 'react';
import type { BlockDefinition } from '../types';
import { Heading, Section } from './shared';

export interface QuoteFormProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  services: { name: string }[];
  detailsLabel: string;
  submitLabel: string;
  /** 'yes': each reply opens a lead in the CRM. */
  leads?: 'yes' | 'no';
  /** 'yes': an optional, unticked “Send me news and offers”. */
  askNews?: 'yes' | 'no';
}

const input =
  'w-full rounded-[var(--s-radius)] border border-[var(--s-border)] bg-[var(--s-bg)] px-4 py-3 text-base';

/**
 * Quote requests. Submissions are stored for the business and emailed to it
 * (Sprint 6.2) until the CRM (Sprint 12). Visitors must agree to be contacted
 * (POPIA); a hidden field catches bots. Inert in the editor and previews.
 */
export const QuoteForm: BlockDefinition<QuoteFormProps> = {
  type: 'QuoteForm',
  version: 1,
  label: 'Quote form',
  description: 'Let visitors ask for a quote; requests arrive in your Enquiries.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    services: {
      kind: 'list',
      label: 'Services to choose from (optional)',
      max: 20,
      item: { name: { kind: 'text', label: 'Service', max: 60 } },
    },
    detailsLabel: { kind: 'text', label: 'Question for details', max: 80 },
    submitLabel: { kind: 'text', inline: true, label: 'Button', max: 30 },
    leads: {
      kind: 'select',
      label: 'Open a lead for each reply',
      options: [
        { value: 'yes', label: 'Yes' },
        { value: 'no', label: 'No' },
      ],
    },
    askNews: {
      kind: 'select',
      label: 'Ask if they’d like news and offers',
      options: [
        { value: 'yes', label: 'Yes' },
        { value: 'no', label: 'No' },
      ],
    },
  },
  defaults: {
    heading: 'Request a quote',
    intro: 'Tell us what you need and we’ll get back to you, usually within a day.',
    services: [],
    detailsLabel: 'Tell us about the job',
    submitLabel: 'Send request',
    leads: 'yes',
    askNews: 'yes',
  },
  render: (p, site) => <QuoteFormView p={p} action={site.formAction} editing={site.editing} />,
};

function QuoteFormView({
  p,
  action,
  editing,
}: {
  p: QuoteFormProps;
  action?: string;
  editing: boolean;
}) {
  const id = useId();
  const live = Boolean(action) && !editing;
  return (
    <Section tone="surface" narrow id="quote">
      <Heading intro={p.intro}>{p.heading}</Heading>
      <form
        method="post"
        action={live ? action : undefined}
        className="mt-8 grid gap-5"
        onSubmit={live ? undefined : (e) => e.preventDefault()}
      >
        <div className="grid gap-5 sm:grid-cols-2">
          <label className="grid gap-1.5" htmlFor={`${id}-name`}>
            <span className="font-medium">Your name</span>
            <input
              id={`${id}-name`}
              name="name"
              required
              maxLength={80}
              autoComplete="name"
              className={input}
            />
          </label>
          <label className="grid gap-1.5" htmlFor={`${id}-phone`}>
            <span className="font-medium">Phone or WhatsApp</span>
            <input
              id={`${id}-phone`}
              name="phone"
              type="tel"
              maxLength={20}
              autoComplete="tel"
              className={input}
            />
          </label>
        </div>
        <label className="grid gap-1.5" htmlFor={`${id}-email`}>
          <span className="font-medium">Email (optional)</span>
          <input
            id={`${id}-email`}
            name="email"
            type="email"
            maxLength={120}
            autoComplete="email"
            className={input}
          />
        </label>
        {p.services.length > 0 && (
          <label className="grid gap-1.5" htmlFor={`${id}-service`}>
            <span className="font-medium">Service</span>
            <select id={`${id}-service`} name="service" className={input}>
              {p.services.map((s, i) => (
                <option key={i}>{s.name}</option>
              ))}
            </select>
          </label>
        )}
        <label className="grid gap-1.5" htmlFor={`${id}-message`}>
          <span className="font-medium">{p.detailsLabel}</span>
          <textarea
            id={`${id}-message`}
            name="message"
            required
            rows={4}
            maxLength={2000}
            className={input}
          />
        </label>
        {/* Bots fill every field; people never see this one. */}
        <div aria-hidden className="absolute -left-[9999px]">
          <label>
            Leave this empty <input name="website" tabIndex={-1} autoComplete="off" />
          </label>
        </div>
        <label className="flex items-start gap-3 text-sm" htmlFor={`${id}-consent`}>
          <input
            id={`${id}-consent`}
            name="consent"
            type="checkbox"
            value="1"
            required
            className="mt-1 size-5 accent-[var(--s-brand)]"
          />
          <span>
            I agree that this business may contact me about my request. My details are used only for
            this.
          </span>
        </label>
        <input type="hidden" name="lead" value={p.leads === 'no' ? '0' : '1'} />
        {p.askNews !== 'no' && (
          <label className="flex items-start gap-3 text-sm" htmlFor={`${id}-news`}>
            <input
              id={`${id}-news`}
              name="news"
              type="checkbox"
              value="1"
              className="mt-1 size-5 accent-[var(--s-brand)]"
            />
            <span>Send me news and offers (optional). I can unsubscribe at any time.</span>
          </label>
        )}
        <div className="flex flex-wrap items-center gap-4">
          <button
            type="submit"
            disabled={!live}
            className="inline-flex min-h-12 items-center rounded-[var(--s-radius)] bg-[var(--s-brand)] px-6 font-semibold text-[var(--s-on-brand)] disabled:opacity-60"
          >
            {p.submitLabel}
          </button>
          {!live && (
            <span className="text-sm text-[var(--s-fg-muted)]">
              Visitors can send this form once your website is live.
            </span>
          )}
        </div>
      </form>
    </Section>
  );
}
