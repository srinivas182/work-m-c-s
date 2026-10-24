import type { ReactNode } from 'react';

/** One section of a page, as stored in WURK's content format (server: ContentDocument). */
export interface StoredBlock {
  id: string;
  type: string;
  version: number;
  props: Record<string, unknown>;
}

export interface ContentDocument {
  schema: 1;
  blocks: StoredBlock[];
}

export interface Branding {
  primary: string;
  secondary: string | null;
  font_pairing: string;
  corners: 'square' | 'soft' | 'round';
  logo: string | null;
  favicon: string | null;
}

/** What every block may know about the site it is rendered on. */
export interface MenuItem {
  label: string;
  href: string;
  children?: MenuItem[];
}

export interface SiteContext {
  businessName: string;
  /** Edited in 4.4; blocks read them from here. */
  menus?: { header: MenuItem[]; footer: MenuItem[] };
  /** Where forms post (set by the public site engine); absent in the editor and previews. */
  formAction?: string;
  logo?: string | null;
  /** True inside the editor: links don't navigate, empty fields show prompts. */
  editing: boolean;
}

/** Editor field description, independent of the editor library (mapped to Puck in 4.3). */
export type Field =
  | {
      kind: 'text';
      label: string;
      multiline?: boolean;
      max?: number;
      /**
       * Editable directly on the page. Only for text a block renders purely as content:
       * in the editor the value becomes an editable element, so it must never be used
       * as a string (attributes, links, comparisons, splitting into paragraphs).
       */
      inline?: boolean;
    }
  /** Option values are the real prop values (true/false, numbers) — never 'true'/'false' strings, which are both truthy. */
  | {
      kind: 'select';
      label: string;
      options: { value: string | number | boolean; label: string }[];
    }
  | { kind: 'image'; label: string }
  | { kind: 'link'; label: string }
  | { kind: 'money'; label: string }
  | { kind: 'list'; label: string; item: Record<string, Field>; max?: number };

export interface BlockDefinition<P extends Record<string, unknown>> {
  type: string;
  /** Bump when props change shape; add a step to `upgrade`. */
  version: number;
  label: string;
  description: string;
  fields: Record<keyof P & string, Field>;
  defaults: P;
  /** Brings props saved by an older version up to the current one. */
  upgrade?: (props: Record<string, unknown>, from: number) => Record<string, unknown>;
  /** True when the section has nothing to show yet: hidden on the live site, a prompt in the editor. */
  isEmpty?: (props: P) => boolean;
  render: (props: P, site: SiteContext) => ReactNode;
}
