import { ChevronDown, Menu } from 'lucide-react';
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
    buttonLabel: { kind: 'text', inline: true, label: 'Button', max: 24 },
    buttonHref: { kind: 'link', label: 'Button link' },
    sticky: {
      kind: 'select',
      label: 'Stays at the top when scrolling',
      options: [
        { value: true, label: 'Yes' },
        { value: false, label: 'No' },
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
        className={`z-30 border-b border-[var(--s-border)] bg-[var(--s-bg)]/90 px-5 backdrop-blur sm:px-8 ${p.sticky ? 'sticky top-0' : ''}`}
      >
        {/* Padding outside the column, like every section, so the logo and button line up with the content below. */}
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-6 py-4">
          <a href={href('/')} className="shrink-0">
            {brand}
          </a>
          {items.length > 0 && (
            <nav
              aria-label="Main"
              className="hidden items-center gap-7 text-[15px] font-medium md:flex"
            >
              {items.map((i) =>
                i.children && i.children.length > 0 ? (
                  // Sub-items: a native disclosure — works without JavaScript and with a keyboard.
                  <details key={i.href + i.label} className="group/sub relative">
                    <summary className="flex cursor-pointer list-none items-center gap-1 text-[var(--s-fg-muted)] hover:text-[var(--s-fg)] [&::-webkit-details-marker]:hidden">
                      {i.label}
                      <ChevronDown
                        className="size-4 transition-transform group-open/sub:rotate-180"
                        aria-hidden
                      />
                    </summary>
                    <div className="absolute left-0 mt-3 grid w-56 gap-1 rounded-[var(--s-radius)] border border-[var(--s-border)] bg-[var(--s-bg)] p-2 shadow-lg">
                      {!i.href.startsWith('#') && (
                        <a
                          href={href(i.href)}
                          className="rounded-lg px-3 py-2.5 font-semibold hover:bg-[var(--s-surface)]"
                        >
                          {i.label}
                        </a>
                      )}
                      {i.children.map((c) => (
                        <a
                          key={c.href + c.label}
                          href={href(c.href)}
                          className="rounded-lg px-3 py-2.5 hover:bg-[var(--s-surface)]"
                        >
                          {c.label}
                        </a>
                      ))}
                    </div>
                  </details>
                ) : (
                  <a
                    key={i.href + i.label}
                    href={href(i.href)}
                    className="text-[var(--s-fg-muted)] hover:text-[var(--s-fg)]"
                  >
                    {i.label}
                  </a>
                ),
              )}
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
                    <div key={i.href + i.label} className="grid gap-1">
                      <a
                        href={href(i.href)}
                        className="rounded-lg px-3 py-2.5 font-medium hover:bg-[var(--s-surface)]"
                      >
                        {i.label}
                      </a>
                      {i.children?.map((c) => (
                        <a
                          key={c.href + c.label}
                          href={href(c.href)}
                          className="ml-3 rounded-lg border-l border-[var(--s-border)] px-3 py-2 text-[var(--s-fg-muted)] hover:bg-[var(--s-surface)]"
                        >
                          {c.label}
                        </a>
                      ))}
                    </div>
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
