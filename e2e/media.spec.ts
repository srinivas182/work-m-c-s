import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

// The picker's AI behaviour (generate panel only where allowed, AI images hidden elsewhere) is covered by
// PhotoPicker.test.tsx; the confirmation dialog's "not included" path by ai.spec.
test('the media library is accessible', async ({ page }, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'media');
  await page.goto(`/${handle}/website/media`);
  await expect(page.getByRole('heading', { level: 1, name: 'Media' })).toBeVisible();
  await expectAccessible(page);
});
