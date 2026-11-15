import { readFileSync } from 'node:fs';
import type { Page, TestInfo } from '@playwright/test';
import { expect, test } from './fixtures';
import { accessibilityProblems, focusObscured } from './support';
import { totp } from './totp';

/*
 * Sprint 18.5a — accessibility crawl of EVERY screen (WCAG 2.2 AA), not a sample. `php artisan wurk:screens` (run by
 * scripts/e2e-setup) lists the URL of every GET route that shows a page, with a record behind each detail screen;
 * routes that aren't pages are reviewed in ScreensCommand::NOT_SCREENS. Each screen must open, and pass axe in the
 * light and the dark theme, and keep every focused control visible (not behind a sticky header or bar), on desktop
 * and mobile.
 */

interface Screen {
  area: 'owner' | 'staff' | 'agent' | 'guest' | 'visitor';
  name: string;
  url: string;
}
const data = JSON.parse(readFileSync('storage/app/e2e-screens.json', 'utf8')) as {
  owner: string;
  password: string;
  screens: Screen[];
  unresolved: { name: string; uri: string; why: string }[];
};
const port = process.env.E2E_PORT ?? '8123';
const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
const CHUNK = 10;

/** The listed URLs are for the test server (scripts/e2e-setup sets the port; signed URLs depend on it). */
const local = (url: string) => url;

test('every GET route is a screen in the crawl or reviewed as not one @screens', () => {
  expect(data.unresolved).toEqual([]);
  expect(data.screens.length).toBeGreaterThan(120);
});

async function signIn(page: Page, area: Screen['area'], testInfo: TestInfo, chunk: number) {
  if (area === 'guest' || area === 'visitor') return;
  const host = area === 'staff' ? `http://localhost:${port}` : '';
  await page.goto(`${host}/sign-in/password`);
  const who =
    area === 'owner'
      ? data.owner
      : area === 'agent'
        ? `agent-${testInfo.project.name}@wurk.co.za`
        : `a11y-${testInfo.project.name}-${chunk + 1}@wurk.co.za`;
  await page.getByLabel('Email or mobile number').fill(who);
  await page
    .getByLabel(/^Password/)
    .fill(area === 'owner' ? data.password : 'sunny days in durban');
  await page.getByRole('button', { name: 'Sign in' }).click();
  if (area !== 'staff') {
    await page.waitForURL((u) => !u.pathname.startsWith('/sign-in'));
    return;
  }
  await expect(page.getByRole('heading', { name: 'Two-step verification' })).toBeVisible();
  const box = page.getByRole('group', { name: 'One-time code' }).getByRole('textbox').first();
  await box.fill(totp(SECRET));
  if (
    !(await page.waitForURL(/\/dashboard$/, { timeout: 5000 }).then(
      () => true,
      () => false,
    ))
  ) {
    await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 1000); // a retry within the same 30 s
    await box.fill(totp(SECRET));
  }
  await expect(page).toHaveURL(/\/dashboard$/);
}

async function setTheme(page: Page, theme: 'light' | 'dark') {
  await page.evaluate((t) => {
    try {
      localStorage.setItem('wurk-theme', t);
    } catch {
      // storage blocked: the attribute alone is enough for this page
    }
    document.documentElement.dataset.theme = t;
  }, theme);
}

const areas: Screen['area'][] = ['owner', 'staff', 'agent', 'guest', 'visitor'];
for (const area of areas) {
  const screens = data.screens.filter((s) => s.area === area);
  for (let c = 0; c * CHUNK < screens.length; c++) {
    const chunk = screens.slice(c * CHUNK, (c + 1) * CHUNK);
    test(`@screens ${area} screens ${c + 1}: ${chunk.map((s) => s.name).join(', ')}`, async ({
      page,
    }, testInfo) => {
      test.setTimeout(180_000);
      await signIn(page, area, testInfo, c);
      const problems: string[] = [];
      for (const screen of chunk) {
        await test.step(screen.name, async () => {
          const response = await page.goto(local(screen.url));
          const status = response?.status() ?? 0;
          if (status >= 400) {
            problems.push(`${screen.name}: HTTP ${status} at ${screen.url}`);
            return;
          }
          const type = (await response?.headerValue('content-type')) ?? '';
          if (!type.includes('text/html')) {
            problems.push(
              `${screen.name}: not a page (${type}) — list it in ScreensCommand::NOT_SCREENS`,
            );
            return;
          }
          await page.waitForLoadState('networkidle').catch(() => undefined);
          await setTheme(page, 'light');
          for (const theme of ['light', 'dark'] as const) {
            await setTheme(page, theme);
            // The editor's canvas is Puck's editing surface (blocks wrapped in its drag handles); the blocks themselves
            // are checked on the published sites, and the editor's keyboard use in its own journey.
            const exclude = /website\.editor|templates\.pages\.edit/.test(screen.name)
              ? ['#preview-frame']
              : [];
            for (const p of await accessibilityProblems(page, exclude)) {
              problems.push(`${screen.name} (${theme}) ${p}`);
            }
          }
          await setTheme(page, 'light');
          const hidden = await focusObscured(page);
          if (hidden.length > 0)
            problems.push(`${screen.name}: focus hidden behind something — ${hidden.join(', ')}`);
        });
      }
      expect(problems).toEqual([]);
    });
  }
}
