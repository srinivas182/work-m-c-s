import { afterEach, describe, expect, it, vi } from 'vitest';
import { matchAnswer, openChat } from './site-chat-panel';

const keywords = [
  { words: ['price', 'cost', 'how much'], answer: 'Braids: R450', link: null },
  { words: ['park', 'parking'], answer: 'Free parking behind us.', link: null },
  {
    words: ['where', 'address'],
    answer: 'You’ll find us at 1 Vilakazi St.',
    link: { kind: 'map', href: 'https://maps.example' },
  },
];

describe('matching', () => {
  it('finds the answer with the most matching words, ignoring case and punctuation', () => {
    expect(matchAnswer('How much does it COST?', keywords)?.answer).toBe('Braids: R450');
    expect(matchAnswer('where can I park', keywords)?.answer).toBe('Free parking behind us.');
    expect(matchAnswer('where is your address?', keywords)?.answer).toBe(
      'You’ll find us at 1 Vilakazi St.',
    );
    expect(matchAnswer('do you do weddings', keywords)).toBeNull();
    expect(matchAnswer('parking', [{ words: ['par'], answer: 'x', link: null }])).toBeNull(); // whole words only
  });
});

describe('the chat panel', () => {
  afterEach(() => vi.unstubAllGlobals());
  const respond = (body: unknown, status = 200) =>
    Promise.resolve(
      new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
  const settle = () =>
    new Promise((r) => setTimeout(r, 0)).then(() => new Promise((r) => setTimeout(r, 0)));

  it('answers buttons and typed questions on the device, closes with Escape, and hands off with the conversation', async () => {
    const fetchMock = vi.fn<(url: string, init?: RequestInit) => Promise<Response>>((url) => {
      if (url === '/chat')
        return respond({
          greeting: 'Hi! How can we help?',
          fallback: 'Not sure — talk to someone?',
          buttons: [{ label: 'Prices', answer: 'Braids: R450', link: null }],
          keywords,
          reply: 'We’ll get back to you as soon as we can.',
        });
      if (url === '/chat/handoff')
        return respond({ ok: true, reply: 'Thanks — we’ll get back to you as soon as we can.' });
      return respond({ ok: true });
    });
    vi.stubGlobal('fetch', fetchMock);
    document.body.innerHTML = '<button data-chat-open aria-label="Chat with us">Chat</button>';
    const opener = document.querySelector<HTMLButtonElement>('[data-chat-open]')!;
    await openChat(opener);
    const dialog = document.querySelector('[role="dialog"]')!;
    expect(dialog.getAttribute('aria-labelledby')).toBe('wurk-chat-title');
    const log = () => document.querySelector('[role="log"]')!.textContent;
    expect(log()).toContain('Hi! How can we help?');

    [...document.querySelectorAll('button')].find((b) => b.textContent === 'Prices')!.click();
    expect(log()).toContain('Braids: R450');
    expect(JSON.parse(String(fetchMock.mock.calls.at(-1)![1]!.body))).toEqual({ label: 'Prices' }); // anonymous: just the button

    const input = document.querySelector<HTMLInputElement>(
      'input[aria-label="Type your question"]',
    )!;
    const ask = input.form!;
    input.value = 'Where can I park?';
    ask.dispatchEvent(new Event('submit', { cancelable: true }));
    input.value = 'do you do weddings';
    ask.dispatchEvent(new Event('submit', { cancelable: true }));
    expect(log()).toContain('Free parking behind us.');
    expect(log()).toContain('Not sure — talk to someone?');
    expect(fetchMock.mock.calls.filter(([u]) => u === '/chat/handoff')).toHaveLength(0); // typing isn't sent anywhere

    [...document.querySelectorAll('button')]
      .find((b) => b.textContent === 'Talk to someone')!
      .click();
    const form = document.querySelector<HTMLFormElement>(
      'form[aria-label="Ask someone to get back to you"]',
    )!;
    (form.elements.namedItem('name') as HTMLInputElement).value = 'Sipho';
    (form.elements.namedItem('phone') as HTMLInputElement).value = '082 555 0101';
    (form.elements.namedItem('consent') as HTMLInputElement).checked = true;
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await settle();
    const sent = JSON.parse(
      String(fetchMock.mock.calls.find(([u]) => u === '/chat/handoff')![1]!.body),
    ) as { name: string; transcript: { from: string; text: string }[] };
    expect(sent.name).toBe('Sipho');
    expect(sent.transcript.map((l) => l.text)).toEqual([
      'Hi! How can we help?',
      'Prices',
      'Braids: R450',
      'Where can I park?',
      'Free parking behind us.',
      'do you do weddings',
      'Not sure — talk to someone?',
    ]);
    expect(log()).toContain('Thanks — we’ll get back to you as soon as we can.');

    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect((dialog as HTMLElement).hidden).toBe(true);
    expect(document.activeElement).toBe(opener);
  });
});
