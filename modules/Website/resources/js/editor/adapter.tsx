import type { Config, Data, Field as PuckField } from '@puckeditor/core';
import { BLOCKS } from '../site/registry';
import { upgradeBlock } from '../site/SiteRenderer';
import type { ContentDocument, Field, SiteContext } from '../site/types';
import { PhotoPicker } from './PhotoPicker';

/*
 * The only place that knows about Puck (Sprint 4 decision 1). Pages are stored in
 * WURK's own format; this converts to and from Puck's, and builds Puck's
 * configuration from our block registry. Replacing or upgrading the editor
 * means changing this file, not the blocks or saved pages.
 */

export interface EditorDeps {
  site: SiteContext;
  photosUrl: string;
}

function moneyField(label: string): PuckField {
  return {
    type: 'custom',
    label,
    render: ({ value, onChange, id }) => (
      <label className="flex items-center gap-2 text-sm" htmlFor={id}>
        <span className="font-medium text-[#5b5776]">R</span>
        <input
          id={id}
          type="number"
          min={0}
          step="0.01"
          inputMode="decimal"
          className="w-full rounded-md border border-[#d9d6e8] px-2 py-1.5"
          value={typeof value === 'number' && value > 0 ? (value / 100).toFixed(2) : ''}
          placeholder="0 = ask us"
          onChange={(e) => onChange(Math.max(0, Math.round(Number(e.target.value || 0) * 100)))}
        />
      </label>
    ),
  };
}

function toPuckField(field: Field, deps: EditorDeps): PuckField {
  switch (field.kind) {
    case 'text':
      return {
        type: field.multiline ? 'textarea' : 'text',
        label: field.label,
        contentEditable: field.inline === true,
      };
    case 'link':
      return { type: 'text', label: field.label, placeholder: '/page, #section or https://…' };
    case 'select':
      return { type: 'select', label: field.label, options: field.options };
    case 'money':
      return moneyField(field.label);
    case 'image':
      return {
        type: 'custom',
        label: field.label,
        render: ({ value, onChange }) => (
          <PhotoPicker
            max={1}
            withAlt={false}
            photosUrl={deps.photosUrl}
            value={typeof value === 'string' && value !== '' ? [{ src: value, alt: '' }] : []}
            onChange={(next) => onChange(next[0]?.src ?? '')}
          />
        ),
      };
    case 'list': {
      const arrayFields = Object.fromEntries(
        Object.entries(field.item).map(([k, f]) => [k, toPuckField(f, deps)]),
      );
      const firstText = Object.entries(field.item).find(([, f]) => f.kind === 'text')?.[0];
      return {
        type: 'array',
        label: field.label,
        arrayFields,
        max: field.max,
        defaultItemProps: emptyItem(field.item),
        // Plain text only: inside the editor an inline field's value may be an element.
        getItemSummary: (item: Record<string, unknown>, index = 0) => {
          const text = firstText ? item[firstText] : undefined;
          return typeof text === 'string' && text.trim() !== '' ? text : `Item ${index + 1}`;
        },
      } as PuckField;
    }
  }
}

function emptyItem(item: Record<string, Field>): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(item).map(([k, f]) => {
      switch (f.kind) {
        case 'money':
          return [k, 0];
        case 'select':
          return [k, f.options[0]?.value ?? ''];
        case 'list':
          return [k, []];
        default:
          return [k, ''];
      }
    }),
  );
}

/** Puck adds its own props (id, puck, editMode) when rendering; blocks only get their own. */
const PUCK_PROPS = new Set(['id', 'puck', 'editMode']);
function blockProps(all: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(all).filter(([k]) => !PUCK_PROPS.has(k)));
}

export function buildConfig(deps: EditorDeps): Config {
  const components = Object.fromEntries(
    Object.values(BLOCKS).map((def) => [
      def.type,
      {
        label: def.label,
        fields: Object.fromEntries(
          Object.entries(def.fields).map(([k, f]) => [k, toPuckField(f as Field, deps)]),
        ),
        defaultProps: def.defaults,
        render: (all: Record<string, unknown>) => (
          <>{def.render(blockProps(all) as never, { ...deps.site, editing: true })}</>
        ),
      },
    ]),
  );

  return { components } as Config;
}

/** WURK document → Puck data (blocks upgraded to their current version first). */
export function toPuck(doc: ContentDocument): Data {
  return {
    root: { props: {} },
    content: doc.blocks.flatMap((b) => {
      const props = upgradeBlock(b);
      return props ? [{ type: b.type, props: { ...props, id: b.id } }] : [];
    }),
  };
}

/** Puck data → WURK document. Unknown types are dropped; every block gets its current version. */
export function fromPuck(data: Data): ContentDocument {
  return {
    schema: 1,
    blocks: data.content.flatMap((c) => {
      const def = BLOCKS[c.type];
      if (!def) return [];
      const { id, ...props } = c.props as Record<string, unknown> & { id: string };
      return [{ id, type: c.type, version: def.version, props }];
    }),
  };
}
