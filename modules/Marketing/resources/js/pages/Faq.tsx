import { Link } from '@inertiajs/react';
import { Site, type SiteProps } from '../components/Site';

const QUESTIONS: [string, string][] = [
  [
    'Do I need a card to start the trial?',
    'No. Most packages include a free trial, and you only add a card or set up a debit order when it ends. We remind you 7, 3 and 1 day before.',
  ],
  [
    'How can I pay?',
    'By card, by debit order (DebiCheck — you approve it on your phone with your bank), or in cash at a WURK office. Every payment gets a VAT invoice.',
  ],
  [
    'What are WURK Credits?',
    'One balance that pays for SMS, WhatsApp and email messages to your customers. Your package includes credits every month; you can buy more any time. If a message fails, the credits come back automatically.',
  ],
  [
    'Can I use my own domain name?',
    'Yes, on packages that include it. Your site always works on name.wurk.co.za as well.',
  ],
  [
    'Can I change package later?',
    'Yes. Upgrades apply straight away and you pay the difference for the rest of the period; downgrades apply at your next renewal.',
  ],
  [
    'Where is my data kept?',
    'In South Africa. WURK follows POPIA: your customers’ details belong to your business, and we never sell or share them.',
  ],
  [
    'What happens if a payment fails?',
    'We tell you straight away with a link to pay another way. Your site and back office keep working for 7 days while we retry, so a declined card never takes you offline overnight.',
  ],
];

export default function Faq(props: SiteProps) {
  return (
    <Site
      {...props}
      title="Questions"
      description="Answers about WURK trials, payment, WURK Credits, domains and data protection."
    >
      <section className="mx-auto max-w-3xl px-5 pt-10 sm:px-8">
        <h1 className="font-display text-4xl font-extrabold tracking-[-0.03em] sm:text-5xl">
          Questions
        </h1>
        <div className="mt-10 divide-y divide-[#1B1640]/10 border-y border-[#1B1640]/10">
          {QUESTIONS.map(([q, a]) => (
            <details key={q} className="group py-5">
              <summary className="font-display flex min-h-11 cursor-pointer list-none items-center justify-between gap-6 text-lg font-semibold">
                {q}
                <span
                  aria-hidden
                  className="text-primary text-2xl leading-none transition-transform group-open:rotate-45"
                >
                  +
                </span>
              </summary>
              <p className="mt-3 max-w-[40rem] text-[17px] leading-relaxed text-[#1B1640]/75">
                {a}
              </p>
            </details>
          ))}
        </div>
        <p className="mt-10 text-[17px] text-[#1B1640]/75">
          Something else?{' '}
          <Link
            href="/contact"
            className="text-primary font-semibold underline-offset-4 hover:underline"
          >
            Send us a message
          </Link>
          .
        </p>
      </section>
    </Site>
  );
}
