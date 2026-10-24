import { createElement, type ReactElement } from 'react';
import { renderToString } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { BLOCKS } from '../site/registry';
import type { ContentDocument, Field, SiteContext } from '../site/types';
import { buildConfig, fromPuck, toPuck } from './adapter';

const site: SiteContext = {
  businessName: 'Glow Salon',
  editing: true,
  menus: { header: [], footer: [] },
};

describe('Puck adapter', () => {
  it('round-trips WURK pages through Puck without loss', () => {
    const doc: ContentDocument = {
      schema: 1,
      blocks: [
        {
          id: 'hero',
          type: 'Hero',
          version: BLOCKS.Hero!.version,
          props: { ...BLOCKS.Hero!.defaults, heading: 'Braids that last' },
        },
        {
          id: 'ServicesPrices-0b7f0a7e-8a1f-4d8e-9a3b-2f1c4e5d6a7b',
          type: 'ServicesPrices',
          version: 1,
          props: { ...BLOCKS.ServicesPrices!.defaults },
        },
      ],
    };

    expect(fromPuck(toPuck(doc))).toEqual(doc);
  });

  it('drops unknown blocks and never stores Puck’s own props', () => {
    const data = toPuck({
      schema: 1,
      blocks: [{ id: 'x', type: 'FromTheFuture', version: 1, props: {} }],
    });
    expect(data.content).toEqual([]);

    const stored = fromPuck({
      root: { props: {} },
      content: [{ type: 'Text', props: { ...BLOCKS.Text!.defaults, id: 't1' } }],
    });
    expect(stored.blocks[0]).toMatchObject({
      id: 't1',
      type: 'Text',
      version: BLOCKS.Text!.version,
    });
    expect(Object.keys(stored.blocks[0]!.props)).not.toContain('id');
  });

  it('offers every block, with inline editing only where a block opted in', () => {
    const config = buildConfig({ site, photosUrl: '/x/photos' });
    expect(Object.keys(config.components).sort()).toEqual(Object.keys(BLOCKS).sort());

    const hero = config.components.Hero!.fields as Record<string, { contentEditable?: boolean }>;
    expect(hero.heading?.contentEditable).toBe(true);
    expect(hero.primaryHref?.contentEditable).toBeUndefined(); // links are never inline
  });
});

/** Every field definition in a block, including the fields of list items. */
function* fieldsOf(
  fields: Record<string, Field>,
  path: string[] = [],
): Generator<[string[], Field]> {
  for (const [key, field] of Object.entries(fields)) {
    yield [[...path, key], field];
    if (field.kind === 'list') yield* fieldsOf(field.item, [...path, key, '0']);
  }
}

/** Props with list items filled in, so nested fields are rendered too. */
function filled(
  fields: Record<string, Field>,
  defaults: Record<string, unknown>,
  withPhotos: boolean,
): Record<string, unknown> {
  // Rendered with and without photos: blocks take different paths (e.g. initials
  // when a team member has no photo), and both must be safe.
  const photo = withPhotos ? '/storage/sites/t/p-1600.webp' : '';
  // Lists are filled recursively (e.g. a menu's sections → dishes), so nested fields render too.
  const item = (itemFields: Record<string, Field>): Record<string, unknown> =>
    Object.fromEntries(
      Object.entries(itemFields).map(([k, f]) => [
        k,
        f.kind === 'money'
          ? 45000
          : f.kind === 'select'
            ? f.options[0]?.value
            : f.kind === 'list'
              ? [item(f.item)]
              : f.kind === 'image'
                ? photo
                : `${k} value`,
      ]),
    );
  const props: Record<string, unknown> = { ...defaults };
  for (const [key, field] of Object.entries(fields)) {
    if (field.kind === 'image') props[key] = photo;
    if (field.kind === 'list') props[key] = [item(field.item)];
  }
  return props;
}

function setAt(target: Record<string, unknown>, path: string[], value: unknown): void {
  let node: Record<string, unknown> = target;
  path.slice(0, -1).forEach((key) => {
    node[key] = Array.isArray(node[key])
      ? [...(node[key] as unknown[])]
      : { ...(node[key] as object) };
    node = node[key] as Record<string, unknown>;
  });
  node[path[path.length - 1]!] = value;
}

describe('inline editing safety', () => {
  // In the editor Puck replaces an inline field's text with an editable element.
  // A block that used that value as a string would crash or print [object Object].
  for (const def of Object.values(BLOCKS)) {
    for (const [path, field] of fieldsOf(def.fields as Record<string, Field>)) {
      if (field.kind !== 'text' || !field.inline) continue;
      it.each([true, false])(
        `${def.type}.${path.join('.')} renders correctly as an editable element (photos: %s)`,
        (withPhotos) => {
          const props = filled(def.fields as Record<string, Field>, def.defaults, withPhotos);
          const editable: ReactElement = createElement(
            'span',
            { 'data-inline': path.join('.') },
            'Edited text',
          );
          setAt(props, path, editable);

          const html = renderToString(<>{def.render(props as never, site)}</>);
          expect(html).toContain(`data-inline="${path.join('.')}"`);
          expect(html).not.toContain('[object Object]');
        },
      );
    }
  }
});

describe('select options', () => {
  it('carry real values, never "true"/"false" text (both of which are truthy)', () => {
    const offenders: string[] = [];
    for (const def of Object.values(BLOCKS)) {
      for (const [path, field] of fieldsOf(def.fields as Record<string, Field>)) {
        if (field.kind !== 'select') continue;
        for (const o of field.options) {
          if (
            o.value === 'true' ||
            o.value === 'false' ||
            (typeof o.value === 'string' && /^\d+$/.test(o.value))
          ) {
            offenders.push(`${def.type}.${path.join('.')}: ${JSON.stringify(o.value)}`);
          }
        }
      }
    }
    expect(offenders).toEqual([]);
  });
});
