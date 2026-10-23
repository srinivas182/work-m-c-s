import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner edits a page on the page itself, it autosaves, and they publish it', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'edit');

  // A new page starts with a header, its title as a heading, and a footer — never blank.
  await page.goto(`/${handle}/website/pages`);
  await page.getByRole('button', { name: 'Add page' }).click();
  await page.getByLabel(/^Page title/).fill('Prices');
  await page.getByRole('dialog').getByRole('button', { name: 'Add page' }).click();
  await page.getByRole('link', { name: 'Edit Prices' }).click();
  await expect(page).toHaveURL(/\/edit$/);

  const canvas = page.frameLocator('iframe').first();
  const heading = canvas.locator('h2 [contenteditable]').first();
  await expect(heading).toHaveText('Prices');
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
  ).toHaveText('Prices for December');

  // Undo is one click away (and is saved too).
  await heading.hover();
  await heading.click();
  await heading.press('End');
  await heading.pressSequentially('!');
  await expect(page.getByRole('status').first()).toHaveText('All changes saved', {
    timeout: 10_000,
  });
  // Known issue (Puck 0.23, docs/sprints/sprint-04.md): Undo pressed while the text is
  // still in editing mode does nothing. Clicking elsewhere first ends editing; then it works.
  await page
    .getByRole('heading', { name: 'Prices' })
    .first()
    .click()
    .catch(() => {});
  await page.frameLocator('iframe').first().locator('footer').first().click();
  await page.getByRole('button', { name: 'Undo' }).click();
  await expect(
    page.frameLocator('iframe').first().locator('h2 [contenteditable]').first(),
  ).toHaveText('Prices for December');
  await expect(page.getByRole('status').first()).toHaveText('All changes saved', {
    timeout: 10_000,
  });

  // Publish with a note; the version list shows it live.
  await page.getByRole('button', { name: 'Publish' }).click();
  await page.getByLabel(/What changed/).fill('December prices');
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Publish now' }).click();
  await expect(page.getByText('Published — version 1 is live.')).toBeVisible();
  await page.getByRole('button', { name: 'Versions' }).click();
  await expect(page.getByRole('dialog').getByText('Version 1')).toBeVisible();
  await expect(page.getByRole('dialog').getByText('December prices')).toBeVisible();
});
