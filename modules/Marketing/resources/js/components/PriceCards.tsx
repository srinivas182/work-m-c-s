import { Check } from 'lucide-react';
import { useState } from 'react';
// Direct imports keep the public site's JavaScript small (no full design-system bundle).
import { Button } from '@/design-system/components/button';
import { rand, vatInclusive } from './Site';

export interface SalePackage {
  id: string;
  key: string;
  name: string;
  description: string;
  type: string;
  monthly_cents: number;
  yearly_cents: number;
  trial_days: number;
  staff_limit: number;
  multi_branch: boolean;
  max_branches: number;
  custom_domain: boolean;
  monthly_credits: number;
  modules: string[];
}

/** Live packages with a monthly / yearly switch. Prices excl. VAT, with the VAT-inclusive figure beneath. */
export function PriceCards({
  packages,
  moduleNames,
  signupUrl,
  headingLevel = 'h3',
}: {
  packages: SalePackage[];
  moduleNames: Record<string, string>;
  signupUrl: string;
  /** h2 when the cards sit directly under the page title, h3 under a section heading. */
  headingLevel?: 'h2' | 'h3';
}) {
  const Heading = headingLevel;
  const [period, setPeriod] = useState<'monthly' | 'yearly'>('monthly');

  if (packages.length === 0) {
    return (
      <p className="text-[#1B1640]/70">
        Packages are being finalised. Leave your details on the contact page and we’ll let you know
        when signup opens.
      </p>
    );
  }

  return (
    <div className="grid gap-8">
      <div
        role="radiogroup"
        aria-label="Billing period"
        className="inline-flex w-fit rounded-full bg-white p-1 shadow-[0_1px_2px_rgba(27,22,64,0.08)] ring-1 ring-[#1B1640]/10"
      >
        {(['monthly', 'yearly'] as const).map((p) => (
          <button
            key={p}
            type="button"
            role="radio"
            aria-checked={period === p}
            onClick={() => setPeriod(p)}
            className={`min-h-11 rounded-full px-5 text-sm font-semibold ${period === p ? 'bg-[#1B1640] text-white' : 'text-[#1B1640]/75 hover:text-[#1B1640]'}`}
          >
            {p === 'monthly' ? 'Monthly' : 'Yearly'}
          </button>
        ))}
      </div>
      <div className="grid gap-6 lg:grid-cols-3">
        {packages.map((p) => {
          const cents = period === 'monthly' ? p.monthly_cents : p.yearly_cents;
          return (
            <section
              key={p.id}
              aria-labelledby={`pkg-${p.key}`}
              className="flex flex-col rounded-[22px] bg-white p-7 ring-1 ring-[#1B1640]/10"
            >
              <Heading id={`pkg-${p.key}`} className="font-display text-xl font-bold">
                {p.name}
              </Heading>
              {p.description && (
                <p className="mt-1.5 text-[15px] text-[#1B1640]/70">{p.description}</p>
              )}
              <p className="mt-6">
                <span className="font-display text-4xl font-bold tracking-tight">
                  {rand(cents)}
                </span>
                <span className="text-[#1B1640]/70">
                  {' '}
                  / {period === 'monthly' ? 'month' : 'year'}
                </span>
              </p>
              <p className="text-sm text-[#1B1640]/65">{rand(vatInclusive(cents))} incl. VAT</p>
              <ul className="mt-6 grid gap-2.5 text-[15px]">
                {/* Catalogue order (website first), not the stored alphabetical order. */}
                {Object.keys(moduleNames)
                  .filter((m) => p.modules.includes(m))
                  .map((m) => (
                    <li key={m} className="flex gap-2.5">
                      <Check className="text-primary mt-0.5 size-4 shrink-0" aria-hidden />
                      {moduleNames[m] ?? m}
                    </li>
                  ))}
                <li className="flex gap-2.5">
                  <Check className="text-primary mt-0.5 size-4 shrink-0" aria-hidden />
                  {p.staff_limit} team {p.staff_limit === 1 ? 'member' : 'members'}
                </li>
                <li className="flex gap-2.5">
                  <Check className="text-primary mt-0.5 size-4 shrink-0" aria-hidden />
                  {p.monthly_credits} WURK Credits a month for SMS and WhatsApp
                </li>
                {p.custom_domain && (
                  <li className="flex gap-2.5">
                    <Check className="text-primary mt-0.5 size-4 shrink-0" aria-hidden />
                    Your own domain name
                  </li>
                )}
              </ul>
              <Button asChild size="lg" className="mt-8 w-full">
                <a href={`${signupUrl}?package=${p.key}&period=${period}`}>
                  {p.trial_days > 0 ? `Try free for ${p.trial_days} days` : `Choose ${p.name}`}
                </a>
              </Button>
            </section>
          );
        })}
      </div>
    </div>
  );
}
