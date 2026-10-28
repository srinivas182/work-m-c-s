import { readFileSync } from 'node:fs';
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

  // Patient records: a document, then the patient's own view (private link → one-time code).
  const port = new URL(String(testInfo.project.use.baseURL)).port;
  const email = `lerato-${testInfo.project.name}@example.com`;
  await page.goto(`/${handle}/records`);
  const add = page.getByRole('form', { name: 'Add a patient' });
  await add.getByLabel('Name').fill('Lerato Mokoena');
  await add.getByLabel('Email').fill(email);
  await add.getByRole('button', { name: 'Add patient' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Lerato Mokoena' })).toBeVisible();
  const doc = page.getByRole('form', { name: 'Add a document' });
  await doc.getByLabel('Title').fill('Assessment');
  await doc
    .getByLabel(/^File/)
    .setInputFiles({
      name: 'assessment.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('Lower back strain, grade 1'),
    });
  await doc.getByRole('button', { name: 'Add' }).click();
  await expect(page.getByText('Document added.')).toBeVisible();
  await page.getByRole('button', { name: 'Send private link' }).click();
  await expect(
    page.getByText('Private link sent. The patient gets a one-time code when they open it.'),
  ).toBeVisible();
  await expectAccessible(page);

  // Mail goes to the log in this environment.
  const log = () => readFileSync('storage/logs/laravel.log', 'utf8');
  const token = [
    ...log().matchAll(new RegExp(`https://${handle}\\.wurk\\.test/records/([A-Za-z0-9]{48})`, 'g')),
  ].at(-1)?.[1];
  expect(token).toBeTruthy();
  const records = `http://${handle}.wurk.test:${port}/records/${token}`;
  await page.goto(records);
  await expect(page.getByRole('heading', { level: 1, name: 'Your documents' })).toBeVisible();
  await expect(page.getByText('Assessment')).toHaveCount(0); // nothing before the code
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Send me a code' }).click();
  const code =
    [...log().matchAll(/Your code for your documents from .* is (\d{6})\./g)].at(-1)?.[1] ?? '';
  await page.getByLabel('Your 6-digit code').fill(code);
  await page.getByRole('button', { name: 'Open my documents' }).click();
  await expect(page.getByText('Hello Lerato Mokoena.', { exact: false })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Documents' })).toContainText('Assessment');
  await expectAccessible(page);
});
