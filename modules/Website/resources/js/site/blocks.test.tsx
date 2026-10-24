import { renderToString } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { internationalDigits } from './blocks/Contact';
import { BLOCKS } from './registry';
import { SiteRenderer, upgradeBlock } from './SiteRenderer';
import type { Branding, ContentDocument } from './types';

const branding: Branding = {
  primary: '#9D174D',
  secondary: null,
  font_pairing: 'classic',
  corners: 'soft',
  logo: null,
  favicon: null,
};
const site = {
  businessName: 'Glow Salon',
  editing: false,
  menus: {
    header: [{ label: 'Prices', href: '#prices' }],
    footer: [{ label: 'Privacy', href: '/privacy' }],
  },
};
const render = (blocks: ContentDocument['blocks'], editing = false) =>
  renderToString(
    <SiteRenderer
      document={{ schema: 1, blocks }}
      branding={branding}
      site={{ ...site, editing }}
    />,
  );

describe('block library', () => {
  it('has 24 blocks whose types and versions the server accepts', () => {
    expect(Object.keys(BLOCKS)).toHaveLength(24);
    for (const [type, def] of Object.entries(BLOCKS)) {
      expect(type).toMatch(/^[A-Z][A-Za-z0-9]{1,40}$/);
      expect(def.type).toBe(type);
      expect(def.version).toBeGreaterThanOrEqual(1);
    }
  });

  it('renders every block on the server with its defaults, in the editor and live', () => {
    for (const def of Object.values(BLOCKS)) {
      const block = { id: def.type.toLowerCase(), type: def.type, version: def.version, props: {} };
      expect(() => render([block], true)).not.toThrow();
      expect(() => render([block], false)).not.toThrow();
    }
  });

  it('upgrades a hero saved by version 1 (single photo) to version 2 (photo list)', () => {
    const props = upgradeBlock({
      id: 'h',
      type: 'Hero',
      version: 1,
      props: { layout: 'split', heading: 'Old', image: '/a.jpg', imageAlt: 'The salon' },
    });
    expect(props).toMatchObject({
      heading: 'Old',
      images: [{ src: '/a.jpg', alt: 'The salon' }],
      autoplay: false,
    });
    expect(props).not.toHaveProperty('image');
    expect(
      render([
        {
          id: 'h',
          type: 'Hero',
          version: 1,
          props: { layout: 'split', heading: 'Old', image: '/a.jpg', imageAlt: 'The salon' },
        },
      ]),
    ).toContain('alt="The salon"');
  });

  it('skips unknown blocks and blocks saved by a newer release instead of breaking the page', () => {
    const html = render([
      { id: 'x', type: 'Mystery', version: 1, props: {} },
      { id: 'y', type: 'Text', version: 99, props: { text: 'From the future' } },
      { id: 'z', type: 'Text', version: 1, props: { text: 'Still here' } },
    ]);
    expect(html).toContain('Still here');
    expect(html).not.toContain('From the future');
  });

  it('never auto-advances a slideshow on the server and only loads the first photo eagerly', () => {
    const html = render([
      {
        id: 's',
        type: 'Hero',
        version: 2,
        props: {
          layout: 'slideshow',
          autoplay: true,
          images: [
            { src: '/1.jpg', alt: 'One' },
            { src: '/2.jpg', alt: 'Two' },
          ],
        },
      },
    ]);
    expect(html).toContain('aria-roledescription="carousel"');
    expect(html).not.toContain('Pause slideshow');
    expect(html.match(/loading="eager"/g)).toHaveLength(1);
    expect(html.match(/loading="lazy"/g)).toHaveLength(1);
  });

  it('shows photo prompts only in the editor', () => {
    const block = { id: 'g', type: 'Gallery', version: 1, props: {} };
    expect(render([block], true)).toContain('Add up to 12 photos');
    expect(render([block], false)).toBe(render([], false));
  });

  it('builds call and WhatsApp numbers for South Africa', () => {
    expect(internationalDigits('082 123 4567')).toBe('27821234567');
    expect(internationalDigits('+27 82 123 4567')).toBe('27821234567');
  });

  it('puts the header and footer outside <main>, with a skip link', () => {
    const html = render([
      { id: 'f', type: 'Footer', version: 1, props: {} },
      { id: 't', type: 'Text', version: 1, props: { text: 'Body' } },
      { id: 'h', type: 'Header', version: 1, props: {} },
    ]);
    expect(html.indexOf('<header')).toBeLessThan(html.indexOf('<main'));
    expect(html.indexOf('</main>')).toBeLessThan(html.indexOf('<footer'));
    expect(html).toContain('Skip to content');
  });

  it('scopes each site’s theme to its own root', () => {
    const html = render([]);
    expect(html).toMatch(/class="wurk-site" style="--s-bg:/);
  });
});
