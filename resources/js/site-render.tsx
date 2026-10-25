/**
 * The website renderer: JSON on stdin (one published page), HTML on stdout.
 * Run once per page at publish (queued) — visitors only ever get the stored HTML,
 * so a renderer failure delays publishing but never takes a live site down.
 * Uses the same SiteRenderer as the editor and previews: no second copy of any block.
 */
import { renderToString } from 'react-dom/server';
import { SiteRenderer } from '@modules/Website/resources/js/site/SiteRenderer';
import { FONT_PAIRINGS } from '@modules/Website/resources/js/site/theme';
import type { Branding, ContentDocument, MenuItem } from '@modules/Website/resources/js/site/types';

interface RenderInput {
  document: ContentDocument;
  branding: Branding;
  site: {
    businessName: string;
    logo: string | null;
    menus: { header: MenuItem[]; footer: MenuItem[] };
    formAction?: string;
  };
}

let raw = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => (raw += chunk));
process.stdin.on('end', () => {
  try {
    const input = JSON.parse(raw) as RenderInput;
    const html = renderToString(
      <SiteRenderer
        document={input.document}
        branding={input.branding}
        site={{ ...input.site, editing: false }}
      />,
    );
    // The site's two web fonts (first family of each stack), so the page can preload just those.
    const pairing = FONT_PAIRINGS[input.branding.font_pairing] ??
      FONT_PAIRINGS.modern ?? { display: '', body: '' };
    const first = (stack: string) => (stack.split(',')[0] ?? '').trim().replace(/^['"]|['"]$/g, '');
    const fonts = [...new Set([first(pairing.display), first(pairing.body)])];
    process.stdout.write(JSON.stringify({ html, fonts }));
  } catch (e) {
    process.stderr.write(String(e instanceof Error ? e.stack : e));
    process.exit(1);
  }
});
