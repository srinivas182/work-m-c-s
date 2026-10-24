import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { renderToString } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { BLOCKS } from './registry';
import { SiteRenderer } from './SiteRenderer';
import type { Field } from './types';

/** WURK's repository templates (modules/Website/templates/*.json). */
const files = import.meta.glob('../../../templates/*.json', {
  eager: true,
  import: 'default',
}) as Record<
  string,
  {
    key: string;
    definition: {
      branding: Record<string, unknown>;
      pages: {
        key: string;
        content: {
          blocks: { id: string; type: string; version: number; props: Record<string, unknown> }[];
        };
      }[];
    };
  }
>;
const templates = Object.values(files);

/** Every prop is a real field; lists are checked item by item; options must be valid choices. */
function check(
  fields: Record<string, Field>,
  props: Record<string, unknown>,
  where: string,
): string[] {
  const problems: string[] = [];
  for (const [key, value] of Object.entries(props)) {
    const field = fields[key];
    if (!field) {
      problems.push(`${where}: unknown field “${key}”`);
      continue;
    }
    if (field.kind === 'select' && !field.options.some((o) => o.value === value))
      problems.push(`${where}.${key}: “${String(value)}” is not an option`);
    if (field.kind === 'list') {
      if (!Array.isArray(value)) problems.push(`${where}.${key}: not a list`);
      else
        value.forEach((item, i) =>
          problems.push(
            ...check(field.item, item as Record<string, unknown>, `${where}.${key}[${i}]`),
          ),
        );
    }
    if (
      field.kind === 'image' &&
      typeof value === 'string' &&
      value !== '' &&
      !existsSync(resolve('public', value.replace(/^\//, '')))
    )
      problems.push(`${where}.${key}: photo ${value} is missing`);
  }
  // Photos inside image lists ({ src, alt }) are checked as items above when the item field is an image.
  return problems;
}

describe('repository templates', () => {
  it('exist', () => {
    expect(templates.length).toBeGreaterThanOrEqual(2);
  });

  for (const template of templates) {
    describe(template.key, () => {
      it('uses only real sections, fields, options and photos', () => {
        const problems: string[] = [];
        for (const page of template.definition.pages) {
          for (const block of page.content.blocks) {
            const def = BLOCKS[block.type];
            if (!def) {
              problems.push(`${page.key}/${block.id}: unknown section ${block.type}`);
              continue;
            }
            if (block.version > def.version)
              problems.push(
                `${page.key}/${block.id}: version ${block.version} is newer than ${def.version}`,
              );
            problems.push(
              ...check(def.fields as Record<string, Field>, block.props, `${page.key}/${block.id}`),
            );
          }
        }
        expect(problems).toEqual([]);
      });

      for (const page of template.definition.pages) {
        it(`renders “${page.key}” with exactly one main heading`, () => {
          const html = renderToString(
            <SiteRenderer
              document={page.content as never}
              branding={
                { ...(template.definition.branding as object), logo: null, favicon: null } as never
              }
              site={{
                businessName: 'Glow Salon',
                editing: false,
                logo: null,
                menus: { header: [], footer: [] },
              }}
            />,
          );
          expect((html.match(/<h1[\s>]/g) ?? []).length).toBe(1);
        });
      }
    });
  }
});
