import { test as base, expect, type Page } from '@playwright/test';

/**
 * Every browser test also checks the Content Security Policy (Sprint 18.1): if the page blocks one of WURK's own
 * scripts, styles, images or connections, the test fails and says what was blocked — so a strict policy can't
 * quietly break a screen.
 */
export const test = base.extend<{ cspGuard: undefined }>({
  cspGuard: [
    async ({ page }, use) => {
      const blocked: string[] = [];
      page.on('console', (m) => {
        const text = m.text();
        if (m.type() === 'error' && /Content Security Policy/i.test(text))
          blocked.push(text.slice(0, 300));
      });
      await use(undefined);
      expect(blocked, 'blocked by the Content Security Policy').toEqual([]);
    },
    { auto: true },
  ],
});

export { expect, type Page };
