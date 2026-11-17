import { expect, test } from './fixtures';
import { expectAccessible } from './support';

/** Sprint 18.6a: WURK's error pages read well, in light and dark, and work without the app's own styles. */
for (const scheme of ['light', 'dark'] as const) {
  test(`the “not found” page is plain and accessible (${scheme})`, async ({ page }, testInfo) => {
    await page.emulateMedia({ colorScheme: scheme });
    const port = new URL(String(testInfo.project.use.baseURL)).port;
    const r = await page.goto(`http://wurk.test:${port}/no-such-page-anywhere`);
    expect(r?.status()).toBe(404);
    await expect(
      page.getByRole('heading', { level: 1, name: 'We can’t find that page' }),
    ).toBeVisible();
    await expect(page.getByRole('link', { name: 'Go to the home page' })).toBeVisible();
    await expectAccessible(page);
  });
}
