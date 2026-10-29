import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner opens Contacts: empty at first, searchable, accessible', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'contacts');
  await page.goto(`/${handle}/contacts`);
  await expect(page.getByRole('heading', { level: 1, name: 'Contacts' })).toBeVisible();
  await expect(page.getByText('No contacts yet')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Export' })).toBeVisible();
  await expectAccessible(page);
  const search = page.getByRole('search', { name: 'Search contacts' });
  await search.getByLabel('Name, phone or email').fill('082 555 0101');
  await search.getByRole('button', { name: 'Search' }).click();
  await expect(page.getByText('No one matches')).toBeVisible();
  await expectAccessible(page);
});
