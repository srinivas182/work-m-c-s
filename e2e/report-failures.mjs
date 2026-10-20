// Prints one GitHub annotation per failed E2E test (first error, trimmed), so failures
// are readable from the PR checks without downloading the HTML report.
import { readFileSync } from 'node:fs';

const report = JSON.parse(readFileSync('test-results/results.json', 'utf8'));
const failures = [];

function walk(suite, titles = []) {
  for (const child of suite.suites ?? []) walk(child, [...titles, child.title]);
  for (const spec of suite.specs ?? []) {
    for (const test of spec.tests ?? []) {
      const last = test.results?.[test.results.length - 1];
      if (last && last.status !== 'passed' && last.status !== 'skipped') {
        const message = (last.errors?.[0]?.message ?? last.error?.message ?? 'unknown error')
          .replace(/\u001b\[[0-9;]*m/g, '')
          .slice(0, 900);
        failures.push(`[${test.projectName}] ${[...titles, spec.title].filter(Boolean).join(' › ')}: ${message}`);
      }
    }
  }
}

for (const suite of report.suites ?? []) walk(suite);
for (const failure of failures) {
  console.log(`::error title=E2E failure::${failure.replace(/\r?\n/g, '%0A')}`);
}
console.log(`${failures.length} failing test(s)`);
