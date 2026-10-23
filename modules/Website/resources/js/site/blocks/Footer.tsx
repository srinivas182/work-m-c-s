import type { BlockDefinition } from '../types';

export interface FooterProps extends Record<string, unknown> {
  text: string;
}

export const Footer: BlockDefinition<FooterProps> = {
  type: 'Footer',
  version: 1,
  label: 'Footer',
  description: 'Your name, a line about you, and your footer menu.',
  fields: { text: { kind: 'text', inline: true, label: 'A line about your business', max: 200 } },
  defaults: { text: '' },
  render: (p, site) => {
    const items = site.menus?.footer ?? [];
    return (
      <footer className="border-t border-[var(--s-border)] px-5 py-12 sm:px-8">
        <div className="mx-auto grid max-w-6xl gap-8 md:grid-cols-[2fr_1fr]">
          <div>
            <p className="font-[family-name:var(--s-font-display)] text-lg font-bold">
              {site.businessName}
            </p>
            {p.text && <p className="mt-2 max-w-md text-[var(--s-fg-muted)]">{p.text}</p>}
          </div>
          {items.length > 0 && (
            <nav aria-label="Footer" className="grid content-start gap-2">
              {items.map((i) => (
                <a
                  key={i.href + i.label}
                  href={site.editing ? undefined : i.href}
                  className="text-[var(--s-fg-muted)] hover:text-[var(--s-fg)]"
                >
                  {i.label}
                </a>
              ))}
            </nav>
          )}
        </div>
        <p className="mx-auto mt-10 max-w-6xl text-sm text-[var(--s-fg-muted)]">
          © {new Date().getFullYear()} {site.businessName}
        </p>
      </footer>
    );
  },
};
