import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('a section’s fields panel is accessible, including list “add” buttons', async ({
  page,
}, testInfo) => {
  test.skip(
    testInfo.project.name === 'mobile',
    'The fields panel sits beside the canvas on desktop; the phone editor is covered by editor.spec.',
  );
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'fields');
  await page.goto(`/${handle}/website/pages`);
  await page.getByRole('link', { name: 'Edit Home' }).click();
  await page.frameLocator('iframe').first().locator('h1').first().click(); // select the Hero
  await expect(page.getByRole('button', { name: 'Add an item' }).first()).toBeVisible(); // Puck's icon-only add button, now named
  await expectAccessible(page);
});
