import { Menu } from 'lucide-react';
import type { BlockDefinition } from '../types';

export interface HeaderProps extends Record<string, unknown> {
  buttonLabel: string;
  buttonHref: string;
  sticky: boolean;
}

/** Logo or name, menu, and one action. The phone menu is a native disclosure: works before any script loads. */
export const Header: BlockDefinition<HeaderProps> = {
  type: 'Header',
  version: 1,
  label: 'Header and menu',
  description: 'Your name or logo, your menu, and a booking button.',
  fields: {
    buttonLabel: { kind: 'text', label: 'Button', max: 24 },
    buttonHref: { kind: 'link', label: 'Button link' },
    sticky: {
      kind: 'select',
      label: 'Stays at the top when scrolling',
      options: [
        { value: 'true', label: 'Yes' },
        { value: 'false', label: 'No' },
      ],
    },
  },
  defaults: { buttonLabel: 'Book now', buttonHref: '#book', sticky: true },
  render: (p, site) => {
    const items = site.menus?.header ?? [];
    const href = (h: string) => (site.editing ? undefined : h);
    const brand = site.logo ? (
      <img
        src={site.logo}
        alt={site.businessName}
        className="h-9 w-auto max-w-[180px] object-contain"
      />
    ) : (
      <span className="font-[family-name:var(--s-font-display)] text-xl font-bold tracking-tight">
        {site.businessName}
      </span>
    );
    return (
      <header
        className={`z-30 border-b border-[var(--s-border)] bg-[var(--s-bg)]/90 backdrop-blur ${p.sticky ? 'sticky top-0' : ''}`}
      >
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-6 px-5 py-4 sm:px-8">
          <a href={href('/')} className="shrink-0">
            {brand}
          </a>
          {items.length > 0 && (
            <nav
              aria-label="Main"
              className="hidden items-center gap-7 text-[15px] font-medium md:flex"
            >
              {items.map((i) => (
                <a
                  key={i.href + i.label}
                  href={href(i.href)}
                  className="text-[var(--s-fg-muted)] hover:text-[var(--s-fg)]"
                >
                  {i.label}
                </a>
              ))}
            </nav>
          )}
          <div className="flex items-center gap-2">
            {p.buttonLabel && (
              <a
                href={href(p.buttonHref)}
                className="inline-flex min-h-11 items-center rounded-[var(--s-radius)] bg-[var(--s-brand)] px-5 text-sm font-semibold text-[var(--s-on-brand)]"
              >
                {p.buttonLabel}
              </a>
            )}
            {items.length > 0 && (
              <details className="group relative md:hidden">
                <summary
                  className="grid size-11 cursor-pointer list-none place-items-center rounded-[var(--s-radius)] border border-[var(--s-border)] [&::-webkit-details-marker]:hidden"
                  aria-label="Menu"
                >
                  <Menu className="size-5" aria-hidden />
                </summary>
                <nav
                  aria-label="Main"
                  className="absolute right-0 mt-2 grid w-56 gap-1 rounded-[var(--s-radius)] border border-[var(--s-border)] bg-[var(--s-bg)] p-2 shadow-lg"
                >
                  {items.map((i) => (
                    <a
                      key={i.href + i.label}
                      href={href(i.href)}
                      className="rounded-lg px-3 py-2.5 font-medium hover:bg-[var(--s-surface)]"
                    >
                      {i.label}
                    </a>
                  ))}
                </nav>
              </details>
            )}
          </div>
        </div>
      </header>
    );
  },
};
