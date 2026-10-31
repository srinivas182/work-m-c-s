import { expect, test } from '@playwright/test';
import { expectAccessible } from './support';

// One demo site with an owner (scripts/e2e-setup). Desktop and phone share it, so nothing here depends on order.
const handle = 'demo-salon-hair-braids';

test('the owner switches the chat on; a visitor gets answers and asks for a person; the owner sees it in Chats', async ({
  page,
}, testInfo) => {
  test.setTimeout(120_000);
  const visitor = `Visitor ${testInfo.project.name}`;

  await page.goto('/sign-in/password');
  await page.getByLabel('Email or mobile number').fill('chat-owner@demo.co.za');
  await page.getByLabel(/^Password/).fill('sunny days in durban');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(new RegExp(`/${handle}$`));

  // Website → Chat: on (if it isn't already).
  await page.goto(`/${handle}/website/chat`);
  await expect(page.getByRole('heading', { level: 1, name: 'Chat' })).toBeVisible();
  const settings = page.getByRole('form', { name: 'Chat settings' });
  const on = settings.getByRole('checkbox', { name: 'Show the chat on my website' });
  if (!(await on.isChecked())) await on.click();
  await settings.getByRole('button', { name: 'Save settings' }).click();
  await expect(page.getByText('Chat saved — it’s on your website.')).toBeVisible();
  await expectAccessible(page);

  // The visitor, on the published site.
  const port = new URL(page.url()).port;
  await page.goto(`http://${handle}.wurk.test:${port}/`);
  await page.getByRole('button', { name: 'Chat with us' }).click();
  const chat = page.getByRole('dialog', { name: 'Chat with us' });
  await expect(chat).toBeVisible();
  const log = chat.getByRole('log', { name: 'Conversation' });
  await expect(log).toContainText('How can we help?');
  await expectAccessible(page);

  const quick = chat.getByRole('group', { name: 'Quick questions' }).getByRole('button');
  const first = (await quick.first().textContent()) ?? '';
  if (first !== 'Talk to someone') {
    await quick.first().click();
    await expect(log.getByRole('listitem')).toHaveCount(3); // greeting, the question, its answer
  }
  await chat.getByLabel('Type your question').fill('Do you do weddings on the moon?');
  await chat.getByRole('button', { name: 'Send', exact: true }).click();
  await expect(log).toContainText('Do you do weddings on the moon?');

  await chat.getByRole('button', { name: 'Talk to someone' }).click();
  const form = chat.getByRole('form', { name: 'Ask someone to get back to you' });
  await form.getByLabel('Your name').fill(visitor);
  await form.getByLabel('Phone').fill('082 555 0101');
  await form.getByLabel('I agree to be contacted about this.').check();
  await form.getByRole('button', { name: 'Send' }).click();
  await expect(log).toContainText('Thanks —');
  await page.keyboard.press('Escape');
  await expect(chat).toBeHidden();
  await expect(page.getByRole('button', { name: 'Chat with us' })).toBeFocused();

  // The owner: Customers → Chats.
  await page.goto(`/${handle}/website/chats`);
  const mine = page.locator('ul[aria-label="Chats"] > li').filter({ hasText: visitor }); // each chat, not its messages
  await expect(mine).toContainText('Do you do weddings on the moon?');
  await expectAccessible(page);
});
