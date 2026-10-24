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

test('editing tips step through, and stay dismissed', async ({ page }, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'tips');
  await page.goto(`/${handle}/website/pages`);
  await page.getByRole('link', { name: 'Edit Home' }).click();

  const tip = page.getByRole('complementary', { name: 'Editing tip' });
  await expect(tip).toContainText('Click any text on the page to change it.');
  await tip.getByRole('button', { name: 'Next tip' }).click();
  await expect(tip).toContainText('Select a section');
  await tip.getByRole('button', { name: 'Got it' }).click();
  await expect(tip).toHaveCount(0);

  await page.reload();
  await expect(page.getByRole('button', { name: 'Publish' }).first()).toBeVisible();
  await expect(page.getByRole('complementary', { name: 'Editing tip' })).toHaveCount(0);
});
