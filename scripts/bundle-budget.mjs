#!/usr/bin/env node
// Data budgets (Sprint 24.4, decision G): what the back office downloads, compressed, kept small for 3G and prepaid data.
//   first load  = the app + the code nearly every page shares + the heaviest ordinary page  ≤ 225 KB today
//                 (the target is 170 KB: React, Inertia and the dialog/menu library are most of it; see sprint-24.md)
//   each page   = the code only that page needs, once the shared code is cached            ≤ 60 KB
// A few heavy tools load only when someone opens them (the page editor, branding, the design showcase) and have their
// own, larger budgets. Run after `vite build`; exits 1 when anything is over.
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const FIRST_LOAD_KB = 225; // a ceiling that only comes down (target 170)
const PAGE_KB = 60;
const HEAVY = { 'Website/resources/js/pages/Editor.tsx': 200, 'Website/resources/js/pages/Branding.tsx': 80, 'Platform/resources/js/pages/DesignSystem.tsx': 80 };

const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const size = new Map();
const kb = (file) => {
  if (!size.has(file)) size.set(file, gzipSync(readFileSync(`public/build/${file}`)).length / 1024);
  return size.get(file);
};
const closure = (key, into = new Set()) => {
  if (into.has(key)) return into;
  into.add(key);
  for (const i of manifest[key].imports ?? []) closure(i, into);
  return into;
};
const entry = closure('resources/js/app.tsx');
const pages = Object.keys(manifest).filter((k) => /\/pages\/.*\.tsx$/.test(k) && !/\.test\./.test(k));
const uses = new Map();
const own = new Map(pages.map((p) => [p, [...closure(p)].filter((k) => !entry.has(k))]));
for (const list of own.values()) for (const k of list) uses.set(k, (uses.get(k) ?? 0) + 1);
const shared = new Set([...uses].filter(([, n]) => n >= Math.max(10, pages.length * 0.25)).map(([k]) => k)); // what nearly every page needs
const sum = (keys) => keys.reduce((t, k) => t + kb(manifest[k].file), 0);

const base = sum([...entry]) + sum([...shared]);
const problems = [];
let heaviest = 0;
for (const [page, list] of own) {
  const mine = sum(list.filter((k) => !shared.has(k)));
  const heavy = Object.entries(HEAVY).find(([p]) => page.endsWith(p));
  const limit = heavy ? heavy[1] : PAGE_KB;
  if (!heavy) heaviest = Math.max(heaviest, mine);
  if (mine > limit) problems.push(`${page}: ${mine.toFixed(1)} KB (budget ${limit} KB)`);
}
const first = base + heaviest;
console.log(`first load ${first.toFixed(1)} KB (budget ${FIRST_LOAD_KB}); shared ${base.toFixed(1)} KB; heaviest ordinary page ${heaviest.toFixed(1)} KB (budget ${PAGE_KB})`);
if (first > FIRST_LOAD_KB) problems.unshift(`first load: ${first.toFixed(1)} KB (budget ${FIRST_LOAD_KB} KB)`);
if (problems.length) {
  console.error('Over the data budget:\n  ' + problems.join('\n  '));
  process.exit(1);
}
