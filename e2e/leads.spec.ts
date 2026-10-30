import { expect, test } from '@playwright/test';
import { expectAccessible, signUpBusiness } from './support';

test('a walk-in becomes a contact; the owner opens a lead, moves it and adds a note', async ({
  page,
}, testInfo) => {
  test.setTimeout(120_000);
  const handle = await signUpBusiness(page, testInfo, 'leads');

  // A real customer: a walk-in booking makes Sipho a contact.
  await page.goto(`/${handle}/bookings/settings`);
  const add = page.getByRole('form', { name: 'Add a service' });
  await add.getByLabel('Name').fill('Bridal hair');
  await add.getByLabel('Length (minutes)').fill('60');
  await add.getByRole('button', { name: 'Save service' }).click();
  await expect(page.getByText('Service saved.')).toBeVisible();
  await page.goto(`/${handle}/bookings?date=2027-05-03`);
  const booking = page.getByRole('form', { name: 'Add a booking' });
  await booking.getByLabel('Time').fill('10:00');
  await booking.getByLabel('Customer name').fill('Sipho');
  await booking.getByRole('button', { name: 'Add booking' }).click();
  await expect(page.getByText('Booking added.')).toBeVisible();

  // Contacts → Sipho → New lead.
  await page.goto(`/${handle}/contacts`);
  await page.getByRole('link', { name: 'Sipho' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Sipho' })).toBeVisible();
  const lead = page.getByRole('form', { name: 'New lead' });
  await lead.getByLabel('What it’s about').fill('Wedding party of 6');
  await lead.getByLabel('Value (R, optional)').fill('4500');
  await lead.getByRole('button', { name: 'Add lead' }).click();
  await expect(page.getByText('Lead added.')).toBeVisible();

  // The board: move it to Quoted (by keyboard-friendly choice, not dragging).
  await page.goto(`/${handle}/leads`);
  await expect(page.getByRole('region', { name: 'New' })).toContainText('Wedding party of 6');
  await expectAccessible(page);
  await page.getByLabel('Move Wedding party of 6 to').selectOption({ label: 'Quoted' });
  await expect(page.getByRole('region', { name: 'Quoted' })).toContainText('Wedding party of 6');

  // The lead's page: a note goes into its history.
  await page.getByRole('link', { name: 'Wedding party of 6' }).click();
  const note = page.getByRole('form', { name: 'Add a note' });
  await note.getByLabel('Note').fill('Sent the quote by WhatsApp.');
  await note.getByRole('button').click();
  await expect(page.getByText('Note added.')).toBeVisible();
  await expect(page.getByRole('list', { name: 'History' })).toContainText(
    'Sent the quote by WhatsApp.',
  );
  await expectAccessible(page);
});
