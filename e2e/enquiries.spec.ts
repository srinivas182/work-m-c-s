import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('a visitor’s quote request reaches Enquiries with its answers, and links to the new contact', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'enquiries');

  // Posted exactly as the published quote form submits it (no session, no CSRF on this public route).
  const sent = await page.request.post(`/forms/${handle}/quote`, {
    form: {
      name: 'Sipho Ndlovu',
      phone: '082 555 0101',
      service: 'Solar install',
      message: 'Quote for a 5 kW system please.',
      consent: '1',
      lead: '1',
      form: 'quote',
      'answers[0][q]': 'Roof type?',
      'answers[0][a]': 'Tiles',
    },
  });
  expect(sent.ok()).toBeTruthy();

  await page.goto(`/${handle}/website/enquiries`);
  await expect(page.getByText('Sipho Ndlovu')).toBeVisible();
  await expect(page.getByText('Quote request · Solar install')).toBeVisible();
  await expect(page.getByText('Roof type?')).toBeVisible();
  await expect(page.getByText('Tiles')).toBeVisible();
  await expectAccessible(page);

  await page.getByRole('link', { name: 'Contact', exact: true }).click(); // exact: the sidebar also has “Contacts”
  await expect(page.getByRole('heading', { level: 1, name: 'Sipho Ndlovu' })).toBeVisible();
  await expect(page.getByRole('list', { name: 'History' })).toContainText(
    'Quote request: Solar install',
  );
  await expectAccessible(page);
});
