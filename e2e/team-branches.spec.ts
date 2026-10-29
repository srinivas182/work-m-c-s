import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner adds a branch and invites a branch manager', async ({ page }, testInfo) => {
  test.setTimeout(120_000);
  const handle = await signUpBusiness(page, testInfo, 'branches', undefined, 'pro');

  await page.goto(`/${handle}/settings/branches`);
  await expect(page.getByRole('heading', { level: 1, name: 'Branches' })).toBeVisible();
  await expect(page.getByText(/^1 of 5 branches open/)).toBeVisible();
  const add = page.getByRole('form', { name: 'Add a branch' });
  await add.getByLabel('Name').fill('Sandton');
  await add.getByLabel('Address').fill('12 Rivonia Rd, Sandton');
  await add.getByRole('button', { name: 'Add branch' }).click();
  await expect(page.getByText('Branch added.')).toBeVisible();
  await expect(page.getByRole('list', { name: 'Branches' })).toContainText(
    '12 Rivonia Rd, Sandton',
  );
  await expectAccessible(page);

  // Bookings by branch: hours at the new branch.
  await page.goto(`/${handle}/bookings/settings`);
  await page.getByLabel('Hours at').selectOption({ label: 'Sandton' });
  const hours = page.getByRole('region', { name: /’s hours$/ }).first();
  await expect(hours).toBeVisible();
  await expectAccessible(page);

  await page.goto(`/${handle}/settings/team`);
  await expect(page.getByRole('heading', { level: 1, name: 'Team' })).toBeVisible();
  await expect(page.getByRole('list', { name: 'Team members' }).getByText('You')).toBeVisible();
  const email = `manager-${testInfo.project.name}@example.com`;
  const invite = page.getByRole('form', { name: 'Invite someone' });
  await invite.getByLabel('Email').fill(email);
  await invite.getByLabel('Role').selectOption({ label: 'Branch manager' });
  await invite.getByRole('button', { name: 'Send invitation' }).click();
  await expect(page.getByText(`Invitation sent to ${email}.`)).toBeVisible();
  await expect(page.getByRole('list', { name: 'Invitations waiting' })).toContainText(email);
  await expectAccessible(page);
  await page.getByRole('button', { name: `Cancel the invitation to ${email}` }).click();
  await expect(page.getByText('Invitation cancelled.')).toBeVisible();
});
