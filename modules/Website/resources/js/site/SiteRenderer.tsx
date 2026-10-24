import './site.css';
import type { ReactNode } from 'react';
import { MainHeading } from './blocks/shared';
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
// Announcement banners sit above the header, at the very top of the page.
const HEAD = new Set(['PromotionBanner', 'Header']);
const FOOT = new Set(['Footer']);

function renderBlock(block: StoredBlock, site: SiteContext) {
  const def = BLOCKS[block.type];
  const props = upgradeBlock(block);
  const out = def && props ? def.render(props as never, site) : null;
  // A block with nothing to show (e.g. an empty gallery on a live site) leaves no trace.
  return out === null || out === undefined ? null : (
    <div key={block.id} data-block={block.type}>
      {out}
    </div>
  );
}

/**
 * Renders a page the same way in the editor, the preview and (Sprint 5) the
 * public site, with correct landmarks: header blocks before <main>, footer
 * blocks after it, and a skip link. Unknown or invalid blocks are skipped
 * rather than breaking the page.
 */
export function SiteRenderer({
  document,
  branding,
  site,
  children,
  fill = false,
}: {
  document: ContentDocument;
  branding: Branding;
  site: SiteContext;
  children?: ReactNode;
  /** Fill the screen with the site's background (whole-page views), not just its content. */
  fill?: boolean;
}) {
  const head = document.blocks
    .filter((b) => HEAD.has(b.type))
    .sort((a, b) => Number(b.type === 'PromotionBanner') - Number(a.type === 'PromotionBanner'));
  const foot = document.blocks.filter((b) => FOOT.has(b.type));
  const body = document.blocks.filter((b) => !HEAD.has(b.type) && !FOOT.has(b.type));
  // Exactly one main heading: the Hero's, or else the first section's (a banner doesn't count).
  const mainHeadingAt = body.some((b) => b.type === 'Hero')
    ? -1
    : body.findIndex((b) => b.type !== 'PromotionBanner');

  return (
    <div
      className={fill ? 'wurk-site min-h-svh' : 'wurk-site'}
      style={{
        ...siteTheme(branding),
        background: 'var(--s-bg)',
        color: 'var(--s-fg)',
        fontFamily: 'var(--s-font-body)',
      }}
    >
      {!site.editing && (
        <a
          href="#content"
          className="sr-only z-50 rounded-[var(--s-radius)] bg-[var(--s-brand)] px-4 py-3 font-semibold text-[var(--s-on-brand)] focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
        >
          Skip to content
        </a>
      )}
      {head.map((b) => renderBlock(b, site))}
      <main id="content">
        {body.map((b, i) =>
          i === mainHeadingAt ? (
            <MainHeading.Provider key={b.id} value>
              {renderBlock(b, site)}
            </MainHeading.Provider>
          ) : (
            renderBlock(b, site)
          ),
        )}
        {children}
      </main>
      {foot.map((b) => renderBlock(b, site))}
    </div>
  );
}
