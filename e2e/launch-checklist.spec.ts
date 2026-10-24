import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('a new owner sees the launch checklist and can confirm steps', async ({ page }, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'launch');
  await page.goto(`/${handle}/website/pages`);

  const steps = page.getByRole('list', { name: 'Launch steps' });
  await expect(page.getByRole('heading', { name: 'Launch your website' })).toBeVisible();
  await expect(steps.getByRole('listitem')).toHaveCount(7);
  // The development sample design already has prices and no stand-in photos; real templates start at 0 (backend test).
  const progress = page.getByText(/\d of 7 done\./);
  const before = Number(((await progress.textContent()) ?? '').match(/(\d) of 7/)?.[1]);
  await expectAccessible(page);
  await page.screenshot({
    path: `test-results/launch-checklist-${testInfo.project.name}.png`,
    fullPage: false,
  });

  await page.getByRole('button', { name: 'Mark as done: Check your opening hours' }).click();
  await expect(page.getByText(`${before + 1} of 7 done.`, { exact: false })).toBeVisible();
  await expect(steps.getByRole('link', { name: 'Check your opening hours (done)' })).toBeVisible();
});
