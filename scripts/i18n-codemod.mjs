#!/usr/bin/env node
// Sprint 24.1b: marks the fixed English texts in React files for translation — JSX text and the text props people
// read (label, title, description, placeholder, aria-label, hint, legend, alt) become __('…'), and the file imports __ (not t: pages often have a local `t`).
// Texts with HTML entities or no letters are left alone. Usage: node scripts/i18n-codemod.mjs <files…>
import { readFileSync, writeFileSync } from 'node:fs';
import ts from 'typescript';

const PROPS = new Set(['label', 'title', 'description', 'placeholder', 'aria-label', 'hint', 'legend', 'alt', 'emptyText', 'confirmLabel', 'cancelLabel']);
const SKIP_PARENTS = new Set(['code', 'pre', 'kbd', 'style', 'script', 'title']);
const quote = (s) => `'${s.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
const readable = (s) => /\p{L}/u.test(s) && !s.includes('&') && !/^[\w-]+(\.[\w-]+)+$/.test(s) && !/^https?:/.test(s);

let changedFiles = 0;
for (const file of process.argv.slice(2)) {
  const src = readFileSync(file, 'utf8');
  const sf = ts.createSourceFile(file, src, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
  const edits = [];
  const visit = (node) => {
    if (ts.isJsxText(node)) {
      const raw = node.getFullText();
      const text = raw.replace(/\s+/g, ' ').trim();
      const parent = node.parent;
      const tag = ts.isJsxElement(parent) ? parent.openingElement.tagName.getText() : '';
      if (text !== '' && readable(text) && !SKIP_PARENTS.has(tag)) {
        const lead = /^\s/.test(raw) ? (raw.match(/^\s*/)[0].includes('\n') ? raw.match(/^\s*/)[0] : ' ') : '';
        const trail = /\s$/.test(raw) ? (raw.match(/\s*$/)[0].includes('\n') ? raw.match(/\s*$/)[0] : ' ') : '';
        // A space next to an expression must survive: JSX keeps single spaces between text and {…}.
        const leadOut = lead === ' ' ? "{' '}" : lead;
        const trailOut = trail === ' ' ? "{' '}" : trail;
        edits.push([node.getFullStart(), node.getEnd(), `${leadOut}{__(${quote(text)})}${trailOut}`]);
      }
    } else if (ts.isJsxAttribute(node) && node.initializer && ts.isStringLiteral(node.initializer)) {
      const name = node.name.getText();
      const value = node.initializer.text;
      if (PROPS.has(name) && readable(value)) {
        edits.push([node.initializer.getStart(), node.initializer.getEnd(), `{__(${quote(value)})}`]);
      }
    }
    ts.forEachChild(node, visit);
  };
  visit(sf);
  if (edits.length === 0) continue;
  let out = src;
  for (const [start, end, text] of edits.sort((a, b) => b[0] - a[0])) {
    out = out.slice(0, start) + text + out.slice(end);
  }
  if (!/import \{[^}]*\b__\b[^}]*\} from '@\/i18n'/.test(out)) {
    const imports = [...out.matchAll(/^import .*?;$/gms)];
    const at = imports.length ? imports[imports.length - 1].index + imports[imports.length - 1][0].length : 0;
    out = out.slice(0, at) + "\nimport { __ } from '@/i18n';" + out.slice(at);
  }
  writeFileSync(file, out);
  changedFiles++;
}
console.log(`${changedFiles} file(s) marked for translation`);
