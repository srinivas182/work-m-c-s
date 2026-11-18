/**
 * Performance gate: mobile Lighthouse on the published home page of every template's demo site
 * (`php artisan website:demo-sites`). Fails below 90, or at layout shift of 0.1 or more.
 * A first run that misses is measured twice more and the median taken (CI machines are noisy).
 *
 *   CHROME_PATH=… node scripts/performance-gate.mjs --port=8124 [--shard=1/2] [--only=key,key]
 */
import { readdirSync, readFileSync } from 'node:fs';
import * as chromeLauncher from 'chrome-launcher';
import lighthouse from 'lighthouse';

const port = Number((process.argv.find((a) => a.startsWith('--port=')) ?? '--port=8124').split('=')[1]);
const MIN_SCORE = 90;
const MAX_CLS = 0.1;
const all = readdirSync('modules/Website/templates').filter((f) => f.endsWith('.json')).map((f) => JSON.parse(readFileSync(`modules/Website/templates/${f}`, 'utf8')).key).sort();
// --only=a,b measures just those templates (local checks); CI measures them all.
const only = (process.argv.find((a) => a.startsWith('--only=')) ?? '').split('=')[1]?.split(',').filter(Boolean) ?? [];
// --shard=i/n measures every n-th template, starting at the i-th (CI runs the gate in parts).
const [shardIndex, shardCount] = ((process.argv.find((a) => a.startsWith('--shard=')) ?? '--shard=1/1').split('=')[1] || '1/1').split('/').map(Number);
const keys = (only.length ? all.filter((k) => only.includes(k)) : all).filter((_, i) => i % shardCount === shardIndex - 1);

const chrome = await chromeLauncher.launch({
  chromePath: process.env.CHROME_PATH,
  chromeFlags: ['--headless=new', '--no-sandbox', '--host-resolver-rules=MAP *.wurk.test 127.0.0.1'],
});
const measure = async (url) => {
  const { lhr } = await lighthouse(url, { port: chrome.port, onlyCategories: ['performance'], output: 'json', logLevel: 'error' });
  if (lhr.runtimeError) throw new Error(`${url}: ${lhr.runtimeError.code}`);
  // What moved, for a page that shifts: the elements Lighthouse blames and how much (to find the cause from CI's log).
  const shifts = (lhr.audits['layout-shifts']?.details?.items ?? []).slice(0, 3)
    .map((i) => `${(i.node?.selector ?? i.node?.nodeLabel ?? '?').slice(0, 90)} (${Number(i.score ?? 0).toFixed(3)})`);
  return { score: Math.round(lhr.categories.performance.score * 100), cls: lhr.audits['cumulative-layout-shift'].numericValue, lcp: lhr.audits['largest-contentful-paint'].numericValue, shifts };
};
const median = (xs) => [...xs].sort((a, b) => a - b)[Math.floor(xs.length / 2)];

const rows = [];
try {
  // Every template's home page, plus product pages from the demo shops (shop:demo).
  const pages = [
    ...keys.map((key) => ({ key, url: `http://demo-${key}.wurk.test:${port}/` })),
    ...['retail-boutique/shop/linen-summer-dress', 'retail-general-store/shop/enamel-mug']
      .filter((path) => keys.includes(path.split('/')[0]))
      .map((path) => ({ key: `${path.split('/')[0]} product`, url: `http://demo-${path.replace('/', '.wurk.test:' + port + '/')}` })),
  ];
  for (const { key, url } of pages) {
    let runs = [await measure(url)];
    if (runs[0].score < MIN_SCORE || runs[0].cls >= MAX_CLS) runs = [...runs, await measure(url), await measure(url)];
    const result = { key, score: median(runs.map((r) => r.score)), cls: median(runs.map((r) => r.cls)), lcp: median(runs.map((r) => r.lcp)), runs: runs.length };
    result.pass = result.score >= MIN_SCORE && result.cls < MAX_CLS;
    rows.push(result);
    console.log(`${result.pass ? '✓' : '✗'} ${key.padEnd(34)} score ${String(result.score).padStart(3)}  CLS ${result.cls.toFixed(3)}  LCP ${(result.lcp / 1000).toFixed(1)}s${result.runs > 1 ? '  (median of 3)' : ''}`);
    if (result.cls >= MAX_CLS) for (const r of runs) console.log(`    shifted: ${r.shifts.join('; ') || 'not reported'}`);
  }
} finally {
  await chrome.kill();
}
const failed = rows.filter((r) => !r.pass);
console.log(`\n${rows.length - failed.length} of ${rows.length} pages pass (mobile ≥ ${MIN_SCORE}, layout shift < ${MAX_CLS}).`);
process.exit(failed.length === 0 ? 0 : 1);
