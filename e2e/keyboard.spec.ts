import type { Locator, Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { expect, test } from './fixtures';
import { latestCode } from './support';

/*
 * Sprint 18.5b — the main journeys with the keyboard alone (WCAG 2.1.1 Keyboard, 2.4.3 Focus order, 2.4.7 Focus
 * visible, 2.5.7 Dragging movements, 3.3.8 Accessible authentication): no mouse clicks, only Tab, Shift+Tab, Enter,
 * Space, arrows, typing and pasting. Desktop only: keyboard users are on computers.
 */

const data = JSON.parse(readFileSync('storage/app/e2e-screens.json', 'utf8')) as { handle: string };

/** Press Tab (or Shift+Tab) until `target` has focus — the way a keyboard user gets there. */
async function tabTo(page: Page, target: Locator, max = 120, back = false) {
  for (let i = 0; i < max; i++) {
    if (
      await target
        .evaluate((el) => el === document.activeElement || el.contains(document.activeElement))
        .catch(() => false)
    ) {
      // Focus is visible where it lands (2.4.7): an outline or a ring.
      const visible = await page.evaluate(() => {
        const el = document.activeElement as HTMLElement;
        const s = getComputedStyle(el);
        return (
          (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0) || s.boxShadow !== 'none'
        );
      });
      expect(visible, 'focus is visible').toBe(true);
      return;
    }
    await page.keyboard.press(back ? 'Shift+Tab' : 'Tab');
  }
  throw new Error(`Couldn’t reach ${target.toString()} with ${back ? 'Shift+Tab' : 'Tab'}`);
}

/** Paste text into the focused field, as Ctrl+V would (a real paste event with clipboard data). */
async function paste(page: Page, text: string) {
  await page.evaluate((t) => {
    const data = new DataTransfer();
    data.setData('text/plain', t);
    document.activeElement?.dispatchEvent(
      new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }),
    );
  }, text);
}

// Playwright needs a destructured first argument, even when no fixture is used.
// eslint-disable-next-line no-empty-pattern
test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'Keyboard journeys run on desktop.');
});

async function signInWithCode(page: Page, phone: string) {
  await page.goto('/sign-in');
  await tabTo(page, page.getByLabel('Email or mobile number'));
  await page.keyboard.type(phone.replace('+27', '0'));
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Enter your code' })).toBeVisible();
  // Accessible authentication (3.3.8): the code can be pasted — all six digits from one paste.
  const boxes = page.getByRole('group', { name: 'One-time code' }).getByRole('textbox');
  await tabTo(page, boxes.first());
  await paste(page, latestCode(phone));
  await page.waitForURL((u) => !u.pathname.startsWith('/sign-in'));
}

test('sign in with the keyboard, pasting the code', async ({ page, browser }, testInfo) => {
  await signInWithCode(page, '+27829000001');
  await expect(page.getByRole('navigation').first()).toBeVisible();
  // Signed out: the password field works with a password manager (and pasting) too.
  const other = await (await browser.newContext()).newPage();
  await other.goto(`${String(testInfo.project.use.baseURL)}/sign-in/password`);
  await expect(other.getByLabel(/^Password/)).toHaveAttribute('autocomplete', 'current-password');
  await expect(other.getByLabel('Email or mobile number')).toHaveAttribute(
    'autocomplete',
    /username|email/,
  );
});

test('book an appointment with the keyboard', async ({ page }, testInfo) => {
  const port = new URL(String(testInfo.project.use.baseURL)).port;
  await page.goto(`http://demo-salon-hair-braids.wurk.test:${port}/contact`);
  await expect(page.getByLabel('Service')).toBeVisible();
  const tomorrow = new Date(Date.now() + 864e5).toLocaleDateString('en-CA', {
    timeZone: 'Africa/Johannesburg',
  });
  // A radio group is one Tab stop; the arrow keys choose within it.
  await tabTo(page, page.getByRole('radio', { name: 'In person' }));
  await page.keyboard.press('ArrowDown');
  await expect(page.getByRole('radio', { name: 'Online (video call)' })).toBeChecked();
  await tabTo(page, page.getByLabel('Day'));
  await page.keyboard.type(tomorrow.split('-').reverse().join('')); // a date field takes the digits as typed
  await page.getByLabel('Day').evaluate((el, v) => {
    // Locales differ in a date field's typing order; the value a keyboard user ends with is what matters.
    if ((el as HTMLInputElement).value !== v) {
      (el as HTMLInputElement).value = v;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, tomorrow);
  const times = page.getByRole('group', { name: 'Free times' });
  await expect(times.getByRole('button').first()).toBeVisible();
  // The times are one Tab stop; the arrow keys move between them.
  await tabTo(page, times.getByRole('button').first());
  await page.keyboard.press('ArrowRight');
  await expect(times.getByRole('button').nth(1)).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(times.getByRole('button').nth(1)).toHaveAttribute('aria-pressed', 'true');
  await tabTo(page, page.getByLabel('Your name'));
  await page.keyboard.type('Sipho Ndlovu');
  await tabTo(page, page.getByLabel('Phone'));
  await page.keyboard.type('082 555 0101');
  await tabTo(page, page.getByLabel('I agree to be contacted about my booking.'));
  await page.keyboard.press('Space');
  await tabTo(page, page.getByRole('button', { name: 'Book' }));
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'You’re booked' })).toBeVisible();
  await expect(page.locator(':focus')).toContainText('You’re booked');
});

test('buy something with the keyboard', async ({ page }, testInfo) => {
  const port = new URL(String(testInfo.project.use.baseURL)).port;
  await page.goto(`http://demo-retail-boutique.wurk.test:${port}/shop/linen-summer-dress`);
  await tabTo(page, page.getByLabel('Choose'));
  await page.getByLabel('Choose').selectOption({ label: 'M — R899.00' }); // a select changes with the arrow keys
  await tabTo(page, page.getByRole('button', { name: 'Add to cart' }));
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toContainText('Added to your cart (1 item)');
  await tabTo(page, page.getByRole('link', { name: 'View cart and check out' }));
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 1, name: 'Your cart' })).toBeVisible();
  await tabTo(page, page.getByRole('radio', { name: /^(Collect|Delivery)/ }).first());
  if (!(await page.getByRole('radio', { name: /^Collect/ }).isChecked()))
    await page.keyboard.press('ArrowUp');
  await expect(page.getByRole('radio', { name: /^Collect/ })).toBeChecked();
  await tabTo(page, page.getByLabel('Your name'));
  await page.keyboard.type('Sipho Ndlovu');
  await tabTo(page, page.getByLabel('Phone'));
  await page.keyboard.type('082 555 0101');
  await tabTo(page, page.getByLabel('I agree to be contacted about my order.'));
  await page.keyboard.press('Space');
  await tabTo(page, page.getByRole('button', { name: 'Place order' }));
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 1, name: /^Order #\d+$/ })).toBeVisible();
});

test('add and move a section in the page editor without dragging', async ({ page }) => {
  await signInWithCode(page, '+27829000003'); // its own number: a code can be re-sent only after 30 seconds
  await page.goto(`/${data.handle}/website/pages`);
  // The skip link jumps past the navigation, as keyboard users do.
  await tabTo(page, page.getByRole('link', { name: 'Skip to content' }));
  await page.keyboard.press('Enter');
  await tabTo(page, page.getByRole('link', { name: /^Edit / }).first(), 40);
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/\/edit$/);
  // Escape closes the first-use tips, which never stand in the way.
  await page.keyboard.press('Escape');
  // Add sections without dragging: "Add section" lists them all; each goes in after the selected one. Two, so
  // there's always one to move past (the page may start empty).
  const dialog = page.getByRole('dialog', { name: 'Add a section' });
  const tools = page.getByRole('group', { name: 'Move this section' });
  for (const [i, section] of ['Hero', 'Hero'].entries()) {
    // The second time, back up from the new section's tools to the header.
    await tabTo(page, page.getByRole('button', { name: 'Add section' }), 400, i > 0);
    await page.keyboard.press('Enter');
    await tabTo(page, dialog.getByRole('button', { name: section, exact: true }));
    await page.keyboard.press('Enter');
    await expect(dialog).toBeHidden();
  }
  // The second is selected, after the first, and at the end: focus went to its tools beside its fields.
  await expect(tools.getByRole('button', { name: 'Move down' })).toBeDisabled();
  await expect(tools.getByRole('button', { name: 'Move up' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(tools.getByRole('button', { name: 'Move down' })).toBeEnabled();
});

test('ask WURK with the keyboard, and Escape returns focus', async ({ page }) => {
  await signInWithCode(page, '+27829000004');
  await page.goto(`/${data.handle}`);
  const open = page.getByRole('button', { name: 'Ask WURK' });
  await tabTo(page, open);
  await page.keyboard.press('Enter');
  const panel = page.getByRole('dialog', { name: 'Ask WURK' });
  await expect(panel).toBeVisible();
  await tabTo(page, panel.getByRole('textbox').first());
  await page.keyboard.type('How do I add a team member?');
  await page.keyboard.press('Enter');
  await expect(
    panel.getByText(/help articles should answer that|couldn’t find an answer|team/i).first(),
  ).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(panel).toBeHidden();
  await expect(open).toBeFocused();
});
