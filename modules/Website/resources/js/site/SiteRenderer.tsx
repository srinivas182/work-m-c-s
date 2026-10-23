import type { ReactNode } from 'react';
import { BLOCKS } from './registry';
import { siteTheme } from './theme';
import type { Branding, ContentDocument, SiteContext, StoredBlock } from './types';

/**
 * Upgrades props saved by older block versions, step by step. Each step's
 * result replaces the previous props (so fields a version removed really go),
 * and defaults fill any gaps only after all steps have run.
 */
export function upgradeBlock(block: StoredBlock): Record<string, unknown> | null {
  const def = BLOCKS[block.type];
  if (!def || block.version > def.version) return null; // unknown, or saved by a newer release
  let props: Record<string, unknown> = { ...block.props };
  for (let from = block.version; from < def.version; from++) {
    props = def.upgrade ? def.upgrade(props, from) : props;
  }
  return { ...def.defaults, ...props };
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
        const out = def && props ? def.render(props as never, site) : null;
        // A block with nothing to show (e.g. an empty gallery on a live site) leaves no trace.
        return out === null || out === undefined ? null : (
          <div key={block.id} data-block={block.type}>
            {out}
          </div>
        );
      })}
      {children}
    </div>
  );
}
