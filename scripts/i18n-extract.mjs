#!/usr/bin/env node
// WURK's texts for translation (Sprint 24.1): every t('…') in the React code and __('…') in PHP, collected into
// lang/_source.json (English, sorted). `--check` fails when that file is out of date, and prints each language's
// coverage (how many of the texts lang/{code}.json translates).
import { readFileSync, readdirSync, statSync, writeFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

const root = new URL('..', import.meta.url).pathname;
const dirs = ['resources/js', 'resources/views', 'app', ...readdirSync(join(root, 'modules')).flatMap((m) => [`modules/${m}/src`, `modules/${m}/resources`])];
const files = [];
const walk = (d) => {
  if (!existsSync(join(root, d))) return;
  for (const f of readdirSync(join(root, d))) {
    const p = join(d, f);
    if (statSync(join(root, p)).isDirectory()) walk(p);
    else if (/\.(tsx?|php)$/.test(f) && !/\.test\.tsx?$/.test(f)) files.push(p);
  }
};
dirs.forEach(walk);

// t('…') / t("…") / __('…') / __("…") with a plain string literal first.
const pattern = /(?<![\w$.])(?:t|__)\(\s*(['"])((?:\\.|(?!\1)[^\\\n])*)\1/g;
// Menu labels and sections (module providers), and fixed flash messages: translated where they're shown.
const menu = /'(?:label|section)'\s*=>\s*'((?:\\.|[^'\\])*)'/g;
const flash = /->with\(\s*'(?:status|warning)',\s*'((?:\\.|[^'\\])*)'\s*\)/g;
const texts = new Set();
for (const f of files) {
  const src = readFileSync(join(root, f), 'utf8');
  if (f.endsWith('.php')) {
    for (const m of [...(f.endsWith('ServiceProvider.php') ? src.matchAll(menu) : []), ...src.matchAll(flash)]) {
      const text = m[1].replace(/\\(['\\])/g, '$1');
      if (/\p{L}/u.test(text)) texts.add(text);
    }
  }
  for (const m of src.matchAll(pattern)) {
    const text = m[2].replace(/\\(['"\\])/g, '$1');
    if (text.trim() !== '') texts.add(text);
  }
}
const sorted = [...texts].sort((a, b) => a.localeCompare(b, 'en'));
const out = JSON.stringify(sorted, null, 2) + '\n';
const target = join(root, 'lang/_source.json');

if (process.argv.includes('--check')) {
  const current = existsSync(target) ? readFileSync(target, 'utf8') : '';
  for (const code of ['zu', 'xh', 'af', 'st', 'sw', 'fr', 'pt', 'ha', 'yo']) {
    const file = join(root, `lang/${code}.json`);
    const done = existsSync(file) ? Object.keys(JSON.parse(readFileSync(file, 'utf8'))).filter((k) => texts.has(k)).length : 0;
    console.log(`${code}: ${done} of ${sorted.length} texts translated`);
  }
  if (current !== out) {
    console.error('lang/_source.json is out of date: run `node scripts/i18n-extract.mjs` and commit it.');
    process.exit(1);
  }
} else {
  writeFileSync(target, out);
  console.log(`${sorted.length} texts written to lang/_source.json`);
}
