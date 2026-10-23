import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

/** Header actions (Undo, Versions, Publish) sit behind Puck's menu toggle on phones. */
async function headerAction(page: Page, name: string | RegExp) {
  const button = page.getByRole('button', { name, exact: typeof name === 'string' });
  if (!(await button.isVisible())) {
    // Phones: with the Blocks drawer open Puck hides its header; close the drawer first.
    const toggle = page.getByRole('button', { name: 'Toggle menu bar' });
    if (!(await toggle.isVisible()))
      await page.getByRole('button', { name: 'Blocks', exact: true }).click();
    await toggle.click();
  }
  await button.click();
}

test('an owner edits a page on the page itself, it autosaves, and they publish it', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'edit');

  // A new page starts with a header, its title as a heading, and a footer — never blank.
  await page.goto(`/${handle}/website/pages`);
  await page.getByRole('button', { name: 'Add page' }).click();
  await page.getByLabel(/^Page title/).fill('Specials');
  await page.getByRole('dialog').getByRole('button', { name: 'Add page' }).click();
  await page.getByRole('link', { name: 'Edit Specials' }).click();
  await expect(page).toHaveURL(/\/edit$/);

  const canvas = page.frameLocator('iframe').first();
  const heading = canvas.locator('h2 [contenteditable]').first();
  await expect(heading).toHaveText('Specials');
  await expect(page.getByRole('status').first()).toHaveText('All changes saved');

  // Click-to-edit on the page, then autosave.
  await heading.hover();
  await heading.click();
  await heading.press('End');
  await heading.pressSequentially(' for December');
  await expect(page.getByRole('status').first()).toHaveText(/Unsaved changes|Saving…/);
  await expect(page.getByRole('status').first()).toHaveText('All changes saved', {
    timeout: 10_000,
  });

  // It really was saved: reload and it is still there.
  await page.reload();
  await expect(
    page.frameLocator('iframe').first().locator('h2 [contenteditable]').first(),
  ).toHaveText('Specials for December');

  // Undo is one click away — even for the first edit after opening the editor — and is saved too.
  await heading.hover();
  await heading.click();
  await heading.press('End');
  await heading.pressSequentially('!');
  await expect(page.getByRole('status').first()).toHaveText('All changes saved', {
    timeout: 10_000,
  });
  await headerAction(page, 'Undo');
  await expect(
    page.frameLocator('iframe').first().locator('h2 [contenteditable]').first(),
  ).toHaveText('Specials for December');
  await expect(page.getByRole('status').first()).toHaveText('All changes saved', {
    timeout: 10_000,
  });

  // Publish with a note; the version list shows it live.
  await headerAction(page, 'Publish');
  await page.getByLabel(/What changed/).fill('December prices');
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Publish now' }).click();
  await expect(page.getByText('Published — version 1 is live.')).toBeVisible();
  await headerAction(page, 'Versions');
  await expect(page.getByRole('dialog').getByText('Version 1')).toBeVisible();
  await expect(page.getByRole('dialog').getByText('December prices')).toBeVisible();
});
