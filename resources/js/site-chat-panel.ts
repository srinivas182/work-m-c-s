// The website chat: an accessible dialog with the business's answers, matched on the visitor's device. Nothing typed
// is sent anywhere until the visitor asks for a person — unless the business has switched on the AI receptionist
// (Sprint 19.3), when typed messages go to it and the visitor is told so. Whenever the receptionist isn't available
// (off, out of credits, over its cap, WURK AI down, offline), the on-device answers carry on.

type Link = { kind: string; href: string } | null;
type Config = {
  greeting: string;
  fallback: string;
  buttons: { label: string; answer: string; link: Link }[];
  keywords: { words: string[]; answer: string; link: Link }[];
  reply: string;
  ai?: { greeting: string; notice: string } | null;
  /** "Talk to someone" opens this page instead of the hand-off form (WURK's own chat: the contact page, 19.5b). */
  handoff_url?: string;
};
/** Where the chat talks to: a business's website (the default), or WURK's sales assistant on wurk.co.za (19.5b). */
export type Endpoints = { config: string; message: string };
const BUSINESS: Endpoints = { config: '/chat', message: '/chat/message' };
let endpoints: Endpoints = BUSINESS;
type Line = { from: 'visitor' | 'bot'; text: string };
/** A booking, class, table or cart the receptionist made: shown under its reply with one action (pay, check out, change). */
export type Card = {
  type?: string;
  service?: string;
  when?: string;
  price?: string | null;
  deposit?: string | null;
  action?: { label: string; url: string };
};
type Saved = { conversation: string | null; lines: (Line & { cards?: Card[] })[] };
type Reply = {
  conversation: string;
  mode: 'ai' | 'person' | 'rules';
  reply: string | null;
  cards: Card[];
};

const SAVED = 'wurk-chat';
const VISITOR = 'wurk-chat-visitor';

/** This browser's random key for its AI conversations (never personal data); kept for the visit if storage is blocked. */
let memoryVisitor: string | null = null;
export function visitorKey(): string {
  try {
    const known = localStorage.getItem(VISITOR);
    if (known && /^[A-Za-z0-9_-]{16,64}$/.test(known)) return known;
  } catch {
    /* storage blocked */
  }
  if (!memoryVisitor) {
    const bytes = new Uint8Array(24);
    crypto.getRandomValues(bytes);
    memoryVisitor = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  }
  try {
    localStorage.setItem(VISITOR, memoryVisitor);
  } catch {
    /* storage blocked: this visit only */
  }
  return memoryVisitor;
}

/** The AI conversation survives moving between pages of the site (this tab only). */
function load(): Saved {
  try {
    const raw = JSON.parse(sessionStorage.getItem(SAVED) ?? 'null') as Saved | null;
    if (raw && Array.isArray(raw.lines))
      return { conversation: raw.conversation ?? null, lines: raw.lines.slice(-60) };
  } catch {
    /* storage blocked or junk */
  }
  return { conversation: null, lines: [] };
}
function save(saved: Saved): void {
  try {
    sessionStorage.setItem(SAVED, JSON.stringify({ ...saved, lines: saved.lines.slice(-60) }));
  } catch {
    /* storage blocked: the chat lasts this page only */
  }
}

/** Only links the receptionist's own tools make (https, or this site): never javascript: or the like. */
function safeUrl(url: string): string | null {
  try {
    const u = new URL(url, location.href);
    return u.protocol === 'https:' || u.origin === location.origin ? u.href : null;
  } catch {
    return null;
  }
}

/** The best keyword answer for what someone typed (most matching words; phrases count), or null. */
export function matchAnswer(
  typed: string,
  keywords: Config['keywords'],
): Config['keywords'][number] | null {
  const text = ` ${typed
    .toLowerCase()
    .replace(/[^\p{L}\p{N}\s]+/gu, ' ')
    .replace(/\s+/g, ' ')
    .trim()} `;
  let best: Config['keywords'][number] | null = null;
  let bestScore = 0;
  for (const k of keywords) {
    const score = k.words.filter((w) => w !== '' && text.includes(` ${w.toLowerCase()} `)).length;
    if (score > bestScore) {
      best = k;
      bestScore = score;
    }
  }
  return best;
}

const LINK_TEXT: Record<string, string> = {
  page: 'Open the page',
  call: 'Call now',
  whatsapp: 'Open WhatsApp',
  map: 'Open the map',
  email: 'Send an email',
};

let panel: HTMLElement | null = null;

export async function openChat(
  opener: HTMLButtonElement,
  where: Endpoints = BUSINESS,
): Promise<void> {
  if (panel) {
    show(opener);
    return;
  }
  endpoints = where;
  const r = await fetch(endpoints.config, { headers: { Accept: 'application/json' } }).catch(
    () => null,
  );
  if (!r || !r.ok) return;
  const config = (await r.json()) as Config;
  panel = build(config, opener);
  document.body.append(panel);
  show(opener);
}

function el<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  attrs: Record<string, string> = {},
  ...children: (Node | string)[]
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
  node.append(...children);
  return node;
}

function show(opener: HTMLButtonElement): void {
  if (!panel) return;
  panel.hidden = false;
  opener.setAttribute('aria-expanded', 'true');
  panel.querySelector<HTMLElement>('[data-first]')?.focus();
}

function build(c: Config, opener: HTMLButtonElement): HTMLElement {
  const transcript: Line[] = [{ from: 'bot', text: c.greeting }];
  const phone =
    typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 640px)').matches;
  const box = el('div', {
    role: 'dialog',
    'aria-modal': 'true',
    'aria-labelledby': 'wurk-chat-title',
    style: phone
      ? 'position:fixed;inset:0;z-index:60;background:#fff;color:#111;display:flex;flex-direction:column'
      : 'position:fixed;right:16px;bottom:16px;z-index:60;width:min(380px,calc(100vw - 32px));max-height:min(600px,calc(100vh - 32px));background:#fff;color:#111;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.3);display:flex;flex-direction:column;overflow:hidden',
  });
  const close = el(
    'button',
    {
      type: 'button',
      'aria-label': 'Close the chat',
      style:
        'border:0;background:none;font-size:1.5rem;line-height:1;cursor:pointer;padding:4px 8px;color:inherit',
    },
    '×',
  );
  const head = el(
    'div',
    {
      style:
        'display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#1f2937;color:#fff',
    },
    el('h2', { id: 'wurk-chat-title', style: 'margin:0;font-size:1rem' }, 'Chat with us'),
    close,
  );
  close.style.color = '#fff';
  // A live log (announced) holding a real list of messages — role="log" on the list itself would remove its list meaning.
  const messages = el('ol', { style: 'list-style:none;margin:0;padding:0;display:grid;gap:8px' });
  const log = el(
    'div',
    {
      role: 'log',
      'aria-live': 'polite',
      'aria-label': 'Conversation',
      style: 'padding:16px;overflow-y:auto;flex:1',
    },
    messages,
  );
  const saved = c.ai ? load() : null;
  const say = (line: Line, link: Link = null, cards: Card[] = []) => {
    transcript.push(line);
    if (saved) {
      saved.lines.push(cards.length ? { ...line, cards } : line);
      save(saved);
    }
    const bubble = el(
      'li',
      {
        style:
          line.from === 'bot'
            ? 'background:#f3f4f6;border-radius:12px;padding:8px 12px;white-space:pre-line;justify-self:start;max-width:90%'
            : 'background:#1f2937;color:#fff;border-radius:12px;padding:8px 12px;justify-self:end;max-width:90%',
      },
      line.text,
    );
    if (link) {
      const a = el(
        'a',
        { href: link.href, style: 'display:block;margin-top:6px;color:inherit;font-weight:600' },
        LINK_TEXT[link.kind] ?? 'Open',
      );
      if (link.kind === 'whatsapp' || link.kind === 'map') a.setAttribute('rel', 'noopener');
      bubble.append(a);
    }
    for (const card of cards) bubble.append(cardView(card));
    messages.append(bubble);
    log.scrollTop = log.scrollHeight;
  };
  const quick = el('div', {
    role: 'group',
    'aria-label': 'Quick questions',
    style: 'display:flex;flex-wrap:wrap;gap:8px;padding:0 16px 8px',
  });
  const pill =
    'border:1px solid #1f2937;background:#fff;color:#1f2937;border-radius:9999px;padding:6px 12px;cursor:pointer';
  const handoff = c.handoff_url
    ? el('a', { href: c.handoff_url, style: `${pill};text-decoration:none` }, 'Talk to someone')
    : el('button', { type: 'button', style: pill }, 'Talk to someone');
  for (const b of c.buttons) {
    const btn = el(
      'button',
      {
        type: 'button',
        style:
          'border:1px solid #d1d5db;background:#fff;color:#111;border-radius:9999px;padding:6px 12px;cursor:pointer',
      },
      b.label,
    );
    btn.addEventListener('click', () => {
      say({ from: 'visitor', text: b.label });
      say({ from: 'bot', text: b.answer }, b.link);
      void fetch('/chat/tap', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ label: b.label }),
      }).catch(() => null); // anonymous
    });
    quick.append(btn);
  }
  quick.append(handoff);
  const input = el('input', {
    type: 'text',
    maxlength: '300',
    'aria-label': 'Type your question',
    placeholder: 'Type your question…',
    autocomplete: 'off',
    style:
      'flex:1;min-width:0;border:1px solid #d1d5db;border-radius:9999px;padding:8px 12px;font:inherit',
  });
  input.setAttribute('data-first', '');
  const send = el(
    'button',
    {
      type: 'submit',
      style:
        'border:0;background:#1f2937;color:#fff;border-radius:9999px;padding:8px 14px;cursor:pointer',
    },
    'Send',
  );
  const ask = el(
    'form',
    { 'aria-label': 'Ask a question', style: 'display:flex;gap:8px;padding:8px 16px 16px' },
    input,
    send,
  );
  const onDevice = (typed: string) => {
    const hit = matchAnswer(typed, c.keywords); // on the visitor's device
    if (hit) say({ from: 'bot', text: hit.answer }, hit.link);
    else say({ from: 'bot', text: c.fallback });
  };
  // While the receptionist works (it may check the calendar): one polite announcement, not a ticking animation.
  const busy = el('p', {
    role: 'status',
    style: 'margin:0;padding:0 16px;font-size:.875rem;color:#4b5563;min-height:1.25em',
  });
  ask.addEventListener('submit', (e) => {
    e.preventDefault();
    const typed = input.value.trim();
    if (!typed || send.disabled) return;
    input.value = '';
    say({ from: 'visitor', text: typed });
    if (!saved) {
      onDevice(typed);
      return;
    }
    send.disabled = true;
    input.setAttribute('aria-busy', 'true');
    busy.textContent = 'The assistant is replying…';
    void fetch(endpoints.message, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        visitor: visitorKey(),
        message: typed.slice(0, 2000),
        conversation: saved.conversation,
      }),
    })
      .then(async (r) => (r.ok ? ((await r.json()) as Reply) : null))
      .catch(() => null)
      .then((r) => {
        busy.textContent = '';
        send.disabled = false;
        input.removeAttribute('aria-busy');
        if (r) {
          saved.conversation = r.conversation;
          save(saved);
        }
        if (r && r.mode !== 'rules' && r.reply)
          say({ from: 'bot', text: r.reply }, null, r.cards ?? []);
        else onDevice(typed); // the receptionist isn't available: the business's own answers carry on
      });
  });
  handoff.addEventListener('click', () => {
    if (c.handoff_url || box.querySelector('[data-handoff]')) return;
    box.insertBefore(handoffForm(c, transcript, say), ask);
    box.querySelector<HTMLInputElement>('[data-handoff] input')?.focus();
  });
  const notice = c.ai
    ? el(
        'p',
        {
          style:
            'margin:0;padding:8px 16px;font-size:.75rem;background:#f9fafb;color:#374151;border-bottom:1px solid #e5e7eb',
        },
        c.ai.notice,
      )
    : null;
  box.append(head, ...(notice ? [notice] : []), log, busy, quick, ask);
  const earlier = saved?.lines ?? [];
  if (saved) saved.lines = [];
  if (earlier.length) {
    for (const line of earlier) say({ from: line.from, text: line.text }, null, line.cards ?? []); // carried over from the last page
  } else {
    say({ from: 'bot', text: c.ai?.greeting ?? c.greeting });
  }
  transcript.shift(); // the first line was recorded twice

  const hide = () => {
    box.hidden = true;
    opener.setAttribute('aria-expanded', 'false');
    opener.focus();
  };
  close.addEventListener('click', hide);
  // Escape closes the chat wherever focus is while it's open.
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !box.hidden) hide();
  });
  box.addEventListener('keydown', (e) => {
    if (e.key !== 'Tab') return; // keep focus inside the dialog
    const focusable = [
      ...box.querySelectorAll<HTMLElement>(
        'button, a[href], input, textarea, [tabindex]:not([tabindex="-1"])',
      ),
    ].filter((n) => !n.hidden && n.offsetParent !== null);
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (!first || !last) return;
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  });
  return box;
}

/** A booking or cart card: what it is, when, the price, and one clear action. */
function cardView(card: Card): HTMLElement {
  const rows: [string, string | null | undefined][] = [
    ['What', card.service],
    ['When', card.when],
    ['Price', card.price],
    ['Deposit', card.deposit],
  ];
  const list = el('dl', {
    style: 'display:grid;grid-template-columns:auto 1fr;gap:2px 10px;margin:0',
  });
  for (const [label, value] of rows) {
    if (value)
      list.append(
        el('dt', { style: 'font-weight:600' }, label),
        el('dd', { style: 'margin:0' }, value),
      );
  }
  const box = el(
    'div',
    {
      'data-card': card.type ?? 'booking',
      style:
        'margin-top:8px;padding:10px;border:1px solid #d1d5db;border-radius:10px;background:#fff;color:#111;display:grid;gap:8px',
    },
    list,
  );
  const href = card.action ? safeUrl(card.action.url) : null;
  if (card.action && href) {
    box.append(
      el(
        'a',
        {
          href,
          target: '_blank',
          rel: 'noopener',
          style:
            'justify-self:start;background:#1f2937;color:#fff;border-radius:9999px;padding:6px 14px;font-weight:600;text-decoration:none',
        },
        card.action.label,
        el(
          'span',
          { style: 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)' },
          ' (opens in a new tab)',
        ),
      ),
    );
  }
  return box;
}

function handoffForm(c: Config, transcript: Line[], say: (l: Line) => void): HTMLFormElement {
  const id = (n: string) => `wurk-chat-${n}`;
  const field = (
    name: string,
    label: string,
    type = 'text',
    extra: Record<string, string> = {},
  ) => {
    const input =
      type === 'textarea'
        ? el('textarea', { id: id(name), name, rows: '2', maxlength: '1000', ...extra })
        : el('input', { id: id(name), name, type, ...extra });
    input.setAttribute(
      'style',
      'width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:6px 10px;font:inherit',
    );
    return el(
      'label',
      { for: id(name), style: 'display:grid;gap:4px;font-size:.875rem' },
      label,
      input,
    );
  };
  const status = el('p', { role: 'status', style: 'margin:0;font-size:.875rem' });
  const form = el(
    'form',
    {
      'data-handoff': '',
      'aria-label': 'Ask someone to get back to you',
      style: 'display:grid;gap:8px;padding:8px 16px;border-top:1px solid #e5e7eb',
    },
    field('name', 'Your name', 'text', { required: '', maxlength: '80', autocomplete: 'name' }),
    field('phone', 'Phone', 'tel', { maxlength: '20', autocomplete: 'tel' }),
    field('email', 'Email', 'email', { maxlength: '120', autocomplete: 'email' }),
    field('message', 'Anything to add? (optional)', 'textarea'),
    el(
      'label',
      { style: 'display:flex;gap:8px;align-items:flex-start;font-size:.875rem' },
      el('input', { type: 'checkbox', name: 'consent', value: '1', required: '' }),
      'I agree to be contacted about this.',
    ),
    el(
      'label',
      { style: 'display:flex;gap:8px;align-items:flex-start;font-size:.875rem' },
      el('input', { type: 'checkbox', name: 'news', value: '1' }),
      'Send me news and offers (optional).',
    ),
    el(
      'div',
      { 'aria-hidden': 'true', style: 'position:absolute;left:-9999px' },
      el('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' }),
    ),
    el(
      'button',
      {
        type: 'submit',
        style:
          'border:0;background:#1f2937;color:#fff;border-radius:9999px;padding:8px 14px;cursor:pointer;justify-self:start',
      },
      'Send',
    ),
    status,
  );
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = new FormData(form);
    if (!String(data.get('phone') ?? '').trim() && !String(data.get('email') ?? '').trim()) {
      status.textContent = 'Give a phone number or an email so we can reply.';
      return;
    }
    status.textContent = 'Sending…';
    const body = { ...Object.fromEntries(data.entries()), transcript: transcript.slice(-30) };
    const r = await fetch('/chat/handoff', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
    }).catch(() => null);
    const json = r
      ? ((await r.json().catch(() => ({}))) as { reply?: string; message?: string })
      : {};
    if (!r || !r.ok) {
      status.textContent = json.message ?? 'That didn’t send — please try again.';
      return;
    }
    const back = form.closest('[role="dialog"]')?.querySelector<HTMLElement>('[data-first]');
    form.remove();
    back?.focus(); // the form had focus: keep it inside the chat
    say({
      from: 'bot',
      text: json.reply ?? `Thanks — ${c.reply.charAt(0).toLowerCase()}${c.reply.slice(1)}`,
    });
  });
  return form;
}
