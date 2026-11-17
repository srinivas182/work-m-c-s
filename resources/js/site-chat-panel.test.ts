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
    expect(document.activeElement).toBe(input); // focus stays inside the chat after the form goes

    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect((dialog as HTMLElement).hidden).toBe(true);
    expect(document.activeElement).toBe(opener);
  });
});

describe('the chat panel with the AI receptionist', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    sessionStorage.clear();
    localStorage.clear();
  });
  const respond = (body: unknown, status = 200) =>
    Promise.resolve(
      new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
  const settle = () =>
    new Promise((r) => setTimeout(r, 0)).then(() => new Promise((r) => setTimeout(r, 0)));
  const config = {
    greeting: 'Hi!',
    fallback: 'Not sure — talk to someone?',
    buttons: [],
    keywords,
    reply: 'We’ll get back to you.',
    ai: {
      greeting: 'Hi! I’m the salon’s AI assistant.',
      notice: 'You’re chatting with an AI assistant.',
    },
  };
  const fresh = async () => {
    vi.resetModules();
    document.body.innerHTML = '<button data-chat-open>Chat</button>';
    const m = await import('./site-chat-panel');
    await m.openChat(document.querySelector<HTMLButtonElement>('[data-chat-open]')!);
    const input = document.querySelector<HTMLInputElement>(
      'input[aria-label="Type your question"]',
    )!;
    const ask = (text: string) => {
      input.value = text;
      input.form!.dispatchEvent(new Event('submit', { cancelable: true }));
    };
    return { ask, log: () => document.querySelector('[role="log"]')!.textContent ?? '' };
  };

  it('tells the visitor it’s an AI, shows its reply with a booking card, and keeps the conversation across pages', async () => {
    const replies: unknown[] = [
      { conversation: 'C'.repeat(26), mode: 'ai', reply: 'Monday 09:00 is free.', cards: [] },
      {
        conversation: 'C'.repeat(26),
        mode: 'ai',
        reply: 'You’re booked!',
        cards: [
          {
            type: 'booking',
            service: 'Knotless braids',
            when: 'Monday 3 May, 09:00',
            price: 'R450.00',
            action: { label: 'Change or cancel', url: 'https://glow.example/book/manage/1' },
          },
          { service: 'Evil', action: { label: 'Click', url: 'javascript:alert(1)' } },
        ],
      },
    ];
    const fetchMock = vi.fn<(url: string, init?: RequestInit) => Promise<Response>>((url) =>
      url === '/chat' ? respond(config) : respond(replies.shift()),
    );
    vi.stubGlobal('fetch', fetchMock);
    const { ask, log } = await fresh();
    expect(document.querySelector('[role="dialog"]')!.textContent).toContain(
      'You’re chatting with an AI assistant.',
    );
    expect(log()).toContain('Hi! I’m the salon’s AI assistant.');

    ask('Braids on Monday?');
    expect(document.querySelector('[role="status"]')!.textContent).toBe(
      'The assistant is replying…',
    );
    await settle();
    expect(log()).toContain('Monday 09:00 is free.');
    const first = JSON.parse(String(fetchMock.mock.calls[1]![1]!.body)) as {
      visitor: string;
      message: string;
      conversation: string | null;
    };
    expect(first.message).toBe('Braids on Monday?');
    expect(first.conversation).toBeNull();
    expect(first.visitor).toMatch(/^[a-f0-9]{48}$/);

    ask('Book it: Sipho, 082 555 0101, yes');
    await settle();
    const second = JSON.parse(String(fetchMock.mock.calls[2]![1]!.body)) as {
      visitor: string;
      conversation: string;
    };
    expect(second.conversation).toBe('C'.repeat(26));
    expect(second.visitor).toBe(first.visitor);
    const card = document.querySelector('[data-card="booking"]')!;
    expect(card.textContent).toContain('Knotless braids');
    expect(card.textContent).toContain('R450.00');
    expect(card.querySelector('a')!.getAttribute('href')).toBe(
      'https://glow.example/book/manage/1',
    );
    expect(document.querySelectorAll('[data-card] a')).toHaveLength(1); // a javascript: link is never shown

    // Another page of the site: the conversation carries on where it was.
    replies.push({ conversation: 'C'.repeat(26), mode: 'ai', reply: 'See you Monday.', cards: [] });
    const next = await fresh();
    expect(next.log()).toContain('You’re booked!');
    expect(next.log().split('Hi! I’m the salon’s AI assistant.')).toHaveLength(2); // the greeting once, not again
    next.ask('Thanks');
    await settle();
    expect(JSON.parse(String(fetchMock.mock.calls.at(-1)![1]!.body))).toMatchObject({
      conversation: 'C'.repeat(26),
    });
  });

  it('falls back to the business’s own answers when the receptionist isn’t available', async () => {
    let next: 'rules' | 'down' = 'rules';
    vi.stubGlobal(
      'fetch',
      vi.fn((url: string) =>
        url === '/chat'
          ? respond(config)
          : next === 'rules'
            ? respond({ conversation: 'C'.repeat(26), mode: 'rules', reply: null, cards: [] })
            : Promise.reject(new Error('offline')),
      ),
    );
    const { ask, log } = await fresh();
    ask('Where can I park?');
    await settle();
    expect(log()).toContain('Free parking behind us.');
    next = 'down';
    ask('do you do weddings');
    await settle();
    expect(log()).toContain('Not sure — talk to someone?');
    expect(document.querySelector('[role="status"]')!.textContent).toBe('');
  });
});
