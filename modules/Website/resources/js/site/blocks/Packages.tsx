import { Check } from 'lucide-react';
import { rand } from '@/lib/money';
import type { BlockDefinition } from '../types';
import { formatDay, isCurrent } from './dates';
import { EmptyHint, Heading, Section } from './shared';

export interface PackageItem {
  name: string;
  price: number;
  from: boolean;
  inclusions: string;
  validUntil: string;
  highlight: boolean;
  buttonLabel: string;
  buttonHref: string;
}

export interface PackagesProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  items: PackageItem[];
}

export const Packages: BlockDefinition<PackagesProps> = {
  type: 'Packages',
  version: 1,
  label: 'Packages & specials',
  description: 'Bundles and limited-time specials; expired ones hide themselves.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    items: {
      kind: 'list',
      label: 'Packages',
      max: 6,
      item: {
        name: { kind: 'text', inline: true, label: 'Package', max: 50 },
        price: { kind: 'money', label: 'Price' },
        from: {
          kind: 'select',
          label: 'Price is',
          options: [
            { value: false, label: 'Fixed' },
            { value: true, label: 'From' },
          ],
        },
        inclusions: {
          kind: 'text',
          label: 'What’s included (one per line)',
          multiline: true,
          max: 400,
        },
        validUntil: { kind: 'text', label: 'Valid until (YYYY-MM-DD, optional)', max: 10 },
        highlight: {
          kind: 'select',
          label: 'Highlight as recommended',
          options: [
            { value: false, label: 'No' },
            { value: true, label: 'Yes' },
          ],
        },
        buttonLabel: { kind: 'text', inline: true, label: 'Button', max: 30 },
        buttonHref: { kind: 'link', label: 'Button goes to' },
      },
    },
  },
  defaults: { heading: 'Packages & specials', intro: '', items: [] },
  render: (p, site) => {
    const shown = site.editing ? p.items : p.items.filter((i) => isCurrent('', i.validUntil));
    return (
      <Section>
        <Heading intro={p.intro}>{p.heading}</Heading>
        {shown.length === 0 ? (
          <div className="mt-8">
            <EmptyHint editing={site.editing}>Add a package or a special.</EmptyHint>
          </div>
        ) : (
          <div className="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            {shown.map((item, i) => {
              const expired = !isCurrent('', item.validUntil);
              const lines =
                typeof item.inclusions === 'string'
                  ? item.inclusions
                      .split('\n')
                      .map((l) => l.trim())
                      .filter(Boolean)
                  : [];
              return (
                <article
                  key={i}
                  className={`flex flex-col gap-5 rounded-[var(--s-radius)] border p-7 ${item.highlight ? 'border-[var(--s-brand)] ring-2 ring-[var(--s-brand)]/20' : 'border-[var(--s-border)]'} ${expired ? 'opacity-60' : ''}`}
                >
                  <div>
                    {item.highlight && (
                      <span className="mb-3 inline-block rounded-full bg-[var(--s-brand)] px-3 py-1 text-xs font-semibold text-[var(--s-on-brand)]">
                        Most popular
                      </span>
                    )}
                    <h3 className="font-[family-name:var(--s-font-display)] text-xl font-bold">
                      {item.name}
                    </h3>
                    <p className="mt-2 text-3xl font-bold text-[var(--s-brand-text)] tabular-nums">
                      {item.price > 0 ? `${item.from ? 'from ' : ''}${rand(item.price)}` : 'Ask us'}
                    </p>
                    {item.validUntil && (
                      <p className="mt-1 text-sm text-[var(--s-fg-muted)]">
                        {expired
                          ? 'Expired — hidden on your site'
                          : `Valid until ${formatDay(item.validUntil)}`}
                      </p>
                    )}
                  </div>
                  {lines.length > 0 && (
                    <ul className="grid gap-2">
                      {lines.map((l, j) => (
                        <li key={j} className="flex gap-2">
                          <Check
                            className="mt-0.5 size-5 shrink-0 text-[var(--s-brand)]"
                            aria-hidden
                          />
                          {l}
                        </li>
                      ))}
                    </ul>
                  )}
                  {item.buttonLabel && (
                    <a
                      href={site.editing ? undefined : item.buttonHref}
                      className={`mt-auto inline-flex min-h-12 items-center justify-center rounded-[var(--s-radius)] px-5 font-semibold ${item.highlight ? 'bg-[var(--s-brand)] text-[var(--s-on-brand)]' : 'border border-[var(--s-border)]'}`}
                    >
                      {item.buttonLabel}
                    </a>
                  )}
                </article>
              );
            })}
          </div>
        )}
      </Section>
    );
  },
};
