import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('an owner contacts support from the back office and sees the ticket (Sprint 16.3)', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'help');
  await page.goto(`/${handle}/support`);
  await expect(page.getByRole('heading', { level: 1, name: 'Support' })).toBeVisible();
  await expectAccessible(page);

  await page.goto(`/${handle}/support/new`);
  await page.getByLabel('What do you need help with?').fill('My domain isn’t connecting');
  await page
    .getByLabel('Tell us more')
    .fill('I added the DNS records yesterday and it still says pending.');
  await page.getByRole('button', { name: 'Next' }).click();
  // No help article matches in this environment, so it goes straight to sending.
  await expect(page.getByRole('button', { name: 'Send to WURK' })).toBeVisible();
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Send to WURK' }).click();

  await expect(
    page.getByRole('heading', { level: 1, name: 'My domain isn’t connecting' }),
  ).toBeVisible();
  await expect(page.getByText(/Your ticket is #\d+/)).toBeVisible();
  await expect(page.getByRole('list', { name: 'Conversation' })).toContainText(
    'I added the DNS records yesterday',
  );
  await expectAccessible(page);
});
