import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('a published page is complete HTML (no JavaScript needed), accessible, and makes no third-party requests', async ({
  page,
  browser,
}, testInfo) => {
  test.skip(
    testInfo.project.name === 'mobile',
    'Publishing from the phone editor is covered by editor.spec; the public page is checked on both sizes by later 5.x tests.',
  );
  test.setTimeout(120_000);
  const handle = await signUpBusiness(page, testInfo, 'live');

  await page.goto(`/${handle}/website/pages`);
  await page.getByRole('link', { name: 'Edit Home' }).click();
  await page.getByRole('button', { name: 'Publish', exact: true }).click();
  await page.getByLabel(/What changed/).fill('Launch');
  await page.getByRole('button', { name: 'Publish now' }).click();
  await expect(page.getByText('Published — version 1 is live.')).toBeVisible();

  const port = new URL(page.url()).port;
  const site = `http://${handle}.wurk.test:${port}/`;

  // JavaScript off: everything a visitor needs is in the HTML.
  const noJs = await browser.newContext({ javaScriptEnabled: false });
  const plain = await noJs.newPage();
  await plain.goto(site);
  await expect(plain.getByRole('heading', { level: 1 })).toBeVisible();
  await expect(plain.locator('link[rel="preload"][as="font"]')).toHaveCount(2);
  await noJs.close();

  // JavaScript on: nothing requested from other sites (POPIA), and accessible.
  const external: string[] = [];
  page.on('request', (r) => {
    const host = new URL(r.url()).hostname;
    if (!host.endsWith('wurk.test') && host !== '127.0.0.1' && host !== 'localhost')
      external.push(r.url());
  });
  const blocked: string[] = [];
  page.on('console', (m) => {
    if (/Content Security Policy/i.test(m.text())) blocked.push(m.text());
  });
  await page.goto(site);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  // The stylesheet really applied (served from the business's own address, allowed by the policy).
  expect(
    await page.getByRole('heading', { level: 1 }).evaluate((h) => getComputedStyle(h).fontFamily),
  ).toContain('Fallback');
  expect(blocked).toEqual([]);
  await expectAccessible(page);
  expect(external).toEqual([]);
});
