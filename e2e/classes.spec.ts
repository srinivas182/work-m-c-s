import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('a guest books a place in a class on a published gym site', async ({ page }, testInfo) => {
  test.setTimeout(60_000);
  const port = new URL(String(testInfo.project.use.baseURL)).port;
  await page.goto(`http://demo-fitness-gym-studio.wurk.test:${port}/contact`);
  const classes = page.getByRole('group', { name: 'Upcoming classes' });
  await expect(classes.getByRole('button').first()).toBeVisible();
  await expectAccessible(page);
  await classes
    .getByRole('button', { name: /^Morning HIIT/ })
    .first()
    .click();
  await page.getByLabel('Your name').fill('Sipho Ndlovu');
  await page.getByLabel('Phone').fill('082 555 0101');
  await page.getByLabel('I agree to be contacted about my class.').check();
  await page.getByRole('button', { name: 'Book my place' }).click();
  await expect(page.locator(':focus')).toContainText('You’re booked');
  await expect(page.locator(':focus')).toContainText('Class: Morning HIIT');
  await expectAccessible(page);
});

test('an owner schedules a class, adds someone and marks them attended', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const handle = await signUpBusiness(page, testInfo, 'classes');
  await page.goto(`/${handle}/bookings/classes`);
  await expect(page.getByRole('heading', { level: 1, name: 'Classes' })).toBeVisible();
  const form = page.getByRole('form', { name: 'Schedule a class' });
  await form.getByLabel('Class name').fill('Pilates');
  await form.getByLabel('Date').fill('2030-05-06');
  await form.getByRole('button', { name: 'Schedule class' }).click();
  await expect(page.getByText('Class scheduled.')).toBeVisible();

  const add = page.getByRole('form', { name: 'Add someone to Pilates' });
  await add.getByLabel('Name').fill('Lerato');
  await add.getByRole('button', { name: 'Add' }).click();
  await expect(page.getByText('Added to the class.')).toBeVisible();
  await expect(page.getByText('1 of 12 places taken')).toBeVisible();
  await page.getByRole('button', { name: 'Attended: Lerato' }).click();
  await expect(page.getByText('Marked as attended.')).toBeVisible();
  await expectAccessible(page);
});
