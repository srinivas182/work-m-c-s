import type { ReactNode } from 'react';
import { BLOCKS } from './registry';
import { siteTheme } from './theme';
import type { Branding, ContentDocument, SiteContext, StoredBlock } from './types';

/** Upgrades props saved by older block versions, step by step. */
export function upgradeBlock(block: StoredBlock): Record<string, unknown> | null {
  const def = BLOCKS[block.type];
  if (!def || block.version > def.version) return null; // unknown, or saved by a newer release
  let props = { ...def.defaults, ...block.props };
  for (let from = block.version; from < def.version; from++) {
    props = { ...props, ...(def.upgrade?.(props, from) ?? {}) };
  }
  return props;
}

/**
 * Renders a page the same way in the editor, the preview and (Sprint 5) the
 * public site. Unknown or invalid blocks are skipped rather than breaking the page.
 */
export function SiteRenderer({
  document,
  branding,
  site,
  children,
}: {
  document: ContentDocument;
  branding: Branding;
  site: SiteContext;
  children?: ReactNode;
}) {
  return (
    <div
      className="wurk-site"
      style={{
        ...siteTheme(branding),
        background: 'var(--s-bg)',
        color: 'var(--s-fg)',
        fontFamily: 'var(--s-font-body)',
      }}
    >
      {document.blocks.map((block) => {
        const def = BLOCKS[block.type];
        const props = upgradeBlock(block);
        return def && props ? (
          <div key={block.id} data-block={block.type}>
            {def.render(props as never, site)}
          </div>
        ) : null;
      })}
      {children}
    </div>
  );
}
