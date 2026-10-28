import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('a health practice registers its practitioner and offers video consultations only with consent', async ({
  page,
}, testInfo) => {
  test.setTimeout(120_000);
  const handle = await signUpBusiness(page, testInfo, 'health', undefined, 'growth');
  await page.goto(`/${handle}/bookings/settings`);

  // A meeting link, then the practitioner's registration, then health mode.
  const links = page.getByRole('form', { name: 'Meeting links' });
  await links
    .getByLabel('For the whole business (optional)')
    .fill('https://meet.google.com/our-room');
  await links.getByRole('button', { name: 'Save meeting links' }).click();
  await expect(page.getByText('Meeting links saved.')).toBeVisible();

  const regs = page.getByRole('list', { name: 'Practitioner registrations' });
  await regs
    .getByRole('button', { name: /^Add registration for / })
    .first()
    .click();
  const reg = page.getByRole('form', { name: /^Registration for / });
  await reg.getByLabel('Registration number').fill('MP0123456');
  await reg.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Registration saved.')).toBeVisible();
  await expect(regs).toContainText('HPCSA MP0123456');

  await page.getByLabel('We’re a health practice').click();
  await expect(
    page.getByText(
      'Health practice is on: online consultations need consent and show registration numbers.',
    ),
  ).toBeVisible();
  await page.getByText('The terms patients agree to').click();
  await expect(page.getByText(/I agree to a consultation by video/)).toBeVisible();
  await expectAccessible(page);
});
