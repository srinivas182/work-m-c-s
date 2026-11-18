import { expect, test } from './fixtures';
import { expectAccessible } from './support';

// Sprint 19.4a. Its own demo site and owner (scripts/e2e-setup), with test credits. There are no AI keys in tests,
// so WURK AI's simulated provider replies "Simulated answer.". Desktop and phone share the site and save the same
// settings, so nothing here depends on order.
const handle = 'demo-salon-nails-lashes-barbers';

test('the owner switches the AI assistant on and tries it; a visitor is told it’s an AI and gets its reply', async ({
  page,
}) => {
  test.setTimeout(120_000);
  await page.goto('/sign-in/password');
  await page.getByLabel('Email or mobile number').fill('ai-owner@demo.co.za');
  await page.getByLabel(/^Password/).fill('sunny days in durban');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(new RegExp(`/${handle}$`));

  await page.goto(`/${handle}/website/chat`);
  const chatSettings = page.getByRole('form', { name: 'Chat settings' });
  const chatOn = chatSettings.getByRole('checkbox', { name: 'Show the chat on my website' });
  if (!(await chatOn.isChecked())) await chatOn.click();
  await chatSettings.getByRole('button', { name: 'Save settings' }).click();
  await expect(page.getByText('Chat saved — it’s on your website.')).toBeVisible();

  const ai = page.getByRole('form', { name: 'AI assistant settings' });
  await expect(page.getByText(/Each reply costs 0\.2 credits/)).toBeVisible(); // the price before switching it on
  const aiOn = ai.getByRole('checkbox', { name: 'Let the AI assistant answer in my website chat' });
  if (!(await aiOn.isChecked())) await aiOn.click();
  await ai.getByRole('button', { name: 'Save the assistant' }).click();
  await expect(
    page.getByText('AI assistant saved — it’s answering in your website chat.'),
  ).toBeVisible();

  // Try it: a test chat, where nothing is booked.
  const tryIt = page.getByRole('form', { name: 'Try the assistant' });
  await tryIt.getByLabel('Your test message').fill('Do you do gel nails?');
  await tryIt.getByRole('button', { name: 'Send' }).click();
  const testChat = page.getByRole('list', { name: 'Test chat' });
  await expect(testChat).toContainText('Do you do gel nails?');
  await expect(testChat).toContainText('Simulated answer.');
  // Scan from the top: sending scrolled the language checkboxes under the phone's sticky header, and axe then
  // measures their target size as only the part showing (a scroll artefact; in view they're full size).
  await page.evaluate(() => window.scrollTo(0, 0));
  await expectAccessible(page);

  // A visitor on the published site, in their own tab (the owner's page may be left zoomed in on a phone).
  const port = new URL(page.url()).port;
  const visitor = await page.context().newPage();
  await visitor.goto(`http://${handle}.wurk.test:${port}/`);
  await visitor.getByRole('button', { name: 'Chat with us' }).click();
  const chat = visitor.getByRole('dialog', { name: 'Chat with us' });
  await expect(chat).toContainText('You’re chatting with an AI assistant.');
  await chat.getByLabel('Type your question').fill('Are you open on Saturday?');
  await chat.getByRole('button', { name: 'Send', exact: true }).click();
  const log = chat.getByRole('log', { name: 'Conversation' });
  await expect(log).toContainText('Simulated answer.');
  await expectAccessible(visitor);

  // Another page load: the conversation is still there.
  await visitor.reload();
  await visitor.getByRole('button', { name: 'Chat with us' }).click();
  await expect(visitor.getByRole('log', { name: 'Conversation' })).toContainText(
    'Are you open on Saturday?',
  );

  // The owner: Customers → Chats → AI assistant conversations; takes one over.
  await page.goto(`/${handle}/website/chats?show=ai`);
  const mine = page
    .getByRole('list', { name: 'AI assistant conversations' })
    .getByRole('listitem')
    .filter({ hasText: 'Are you open on Saturday?' })
    .first();
  await expect(mine).toContainText('Simulated answer.');
  await expectAccessible(page);
  const takeOver = mine.getByRole('button', { name: 'Take over' });
  if (await takeOver.isVisible()) {
    await takeOver.click();
    await expect(page.getByText(/You have this chat: the assistant has stopped\./)).toBeVisible();
  }
});
