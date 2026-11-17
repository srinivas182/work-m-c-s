import { expect, test } from './fixtures';
import { expectAccessible } from './support';

// Sprint 19.5b. HQ has switched the sales assistant on (scripts/e2e-setup). There are no AI keys in tests, so WURK AI's
// simulated provider replies "Simulated answer.".
test('a visitor on wurk.co.za chats with WURK’s sales assistant, and can reach a person', async ({
  page,
}, testInfo) => {
  const port = new URL(testInfo.project.use.baseURL ?? 'http://127.0.0.1:8123').port;
  await page.goto(`http://wurk.test:${port}/pricing`);
  await page.getByRole('button', { name: 'Chat with us' }).click();
  const chat = page.getByRole('dialog', { name: 'Chat with us' });
  await expect(chat).toContainText('You’re chatting with WURK’s AI assistant.');
  await expect(chat.getByRole('log', { name: 'Conversation' })).toContainText(
    'WURK’s AI assistant',
  );
  await chat.getByLabel('Type your question').fill('Which package suits a salon with 3 staff?');
  await chat.getByRole('button', { name: 'Send', exact: true }).click();
  await expect(chat.getByRole('log', { name: 'Conversation' })).toContainText('Simulated answer.');
  await expectAccessible(page);

  await chat.getByRole('link', { name: 'Talk to someone' }).click();
  await expect(page).toHaveURL(/\/contact$/);
});
