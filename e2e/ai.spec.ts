import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner writes their first website with AI for free; more needs WURK AI in the package', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'ai'); // Starter: WURK AI not included
  await page.goto(`/${handle}/website/ai`);
  await expect(
    page.getByRole('heading', { level: 1, name: 'Write your website with AI' }),
  ).toBeVisible();
  await expectAccessible(page);

  await page
    .getByLabel('What do you do?')
    .fill('Braids and natural hair care for women and children.');
  await page.getByRole('button', { name: 'Write my website' }).click();
  const dialog = page.getByRole('dialog', { name: 'Write my website?' });
  await expect(dialog.getByText('Free — your first one is on us.')).toBeVisible();
  await expectAccessible(page);
  await dialog.getByRole('button', { name: 'Go ahead' }).click();
  await expect(page.getByText(/WURK AI wrote \d+ pages?/)).toBeVisible();

  // The free one is used; Starter doesn't include WURK AI, so the next one offers an upgrade.
  await page.goto(`/${handle}/website/ai`);
  await page.getByLabel('What do you do?').fill('Braids and natural hair care.');
  await page.getByRole('button', { name: 'Write my website' }).click();
  const again = page.getByRole('dialog', { name: 'Write my website?' });
  await expect(again.getByText('WURK AI isn’t included in your package.')).toBeVisible();
  await expect(again.getByRole('link', { name: 'Upgrade' })).toBeVisible();
});
