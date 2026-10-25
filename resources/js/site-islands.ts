/**
 * Islands for published websites: the few interactive parts, enhancing static HTML that
 * already works without JavaScript (the slideshow swipes, before & after shows a 50/50 split).
 * Mirrors the React behaviour of BeforeAfter, HeroSlideshow and PromotionBanner.
 */
const ACTIVE = ['w-5', 'bg-[var(--s-brand)]'];
const INACTIVE = ['w-1.5', 'bg-[var(--s-border)]'];

function beforeAfter(root: HTMLElement): void {
  const range = root.querySelector<HTMLInputElement>('[data-ba-range]');
  const clip = root.querySelector<HTMLElement>('[data-ba-clip]');
  const line = root.querySelector<HTMLElement>('[data-ba-line]');
  range?.addEventListener('input', () => {
    const split = Number(range.value);
    if (clip) clip.style.clipPath = `inset(0 ${100 - split}% 0 0)`;
    if (line) line.style.left = `${split}%`;
  });
}

function slideshow(root: HTMLElement): void {
  const track = root.querySelector<HTMLElement>('[data-hs-track]');
  const dots = [...root.querySelectorAll<HTMLButtonElement>('[data-hs-dot]')];
  const pause = root.querySelector<HTMLButtonElement>('[data-hs-pause]');
  if (!track || dots.length < 2) return;
  const count = dots.length;
  let index = 0;
  let paused = false;
  let held = false;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const autoplay = root.dataset.autoplay === '1' && !reduced;
  // The server renders it hidden (it can't know the visitor's preference): show it when the slideshow moves.
  if (pause) pause.hidden = reduced;

  const mark = (i: number) => {
    index = i;
    dots.forEach((d, j) => {
      d.setAttribute('aria-current', String(j === i));
      const bar = d.querySelector('[data-hs-bar]');
      bar?.classList.remove(...(j === i ? INACTIVE : ACTIVE));
      bar?.classList.add(...(j === i ? ACTIVE : INACTIVE));
    });
  };
  const go = (to: number) => {
    const next = (to + count) % count;
    const slide = track.children[next] as HTMLElement | undefined;
    track.scrollTo({ left: slide?.offsetLeft ?? 0, behavior: reduced ? 'auto' : 'smooth' });
    mark(next);
  };
  const setPaused = (value: boolean) => {
    paused = value;
    if (!pause) return;
    pause.setAttribute('aria-label', paused ? 'Play slideshow' : 'Pause slideshow');
    pause.querySelector('[data-hs-icon="pause"]')?.classList.toggle('hidden', paused);
    pause.querySelector('[data-hs-icon="play"]')?.classList.toggle('hidden', !paused);
  };

  dots.forEach((d, i) => d.addEventListener('click', () => go(i)));
  root.querySelector('[data-hs-prev]')?.addEventListener('click', () => go(index - 1));
  root.querySelector('[data-hs-next]')?.addEventListener('click', () => go(index + 1));
  pause?.addEventListener('click', () => setPaused(!paused));
  track.addEventListener(
    'scroll',
    () => mark(Math.round(track.scrollLeft / Math.max(1, track.clientWidth))),
    { passive: true },
  );
  for (const [type, value] of [
    ['mouseenter', true],
    ['mouseleave', false],
    ['focusin', true],
    ['focusout', false],
  ] as const) {
    root.addEventListener(type, () => (held = value));
  }
  if (autoplay) {
    window.setInterval(() => {
      if (!paused && !held) go(index + 1);
    }, 7000);
  }
}

function promotion(root: HTMLElement): void {
  root.querySelector('[data-promo-close]')?.addEventListener('click', () => root.remove());
}

/* Booking: service → team member → day → free time → details → confirmation. Elements are built
   with DOM methods only (never HTML strings), so names from the business can't inject markup. */
type BookService = {
  id: string;
  name: string;
  duration: number;
  price_cents: number;
  members: { id: string; name: string }[];
};

function el<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  props: Partial<HTMLElementTagNameMap[K]> & { class?: string } = {},
  ...children: (Node | string)[]
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  const { class: cls, ...rest } = props;
  if (cls) node.className = cls;
  Object.assign(node, rest);
  node.append(...children);
  return node;
}
const field =
  'mt-1 w-full rounded-[var(--s-radius)] border border-[var(--s-border)] bg-[var(--s-bg)] px-4 py-3 text-base';
const button =
  'rounded-[var(--s-radius)] bg-[var(--s-brand)] px-5 py-3 font-semibold text-[var(--s-on-brand)] disabled:opacity-60';

async function booking(root: HTMLElement): Promise<void> {
  const api = root.dataset.api ?? '/book';
  let services: BookService[] = [];
  try {
    const r = await fetch(`${api}/services`, { headers: { Accept: 'application/json' } });
    if (!r.ok) return; // not available: keep the "call or message us" fallback
    services = ((await r.json()) as { services: BookService[] }).services;
  } catch {
    return;
  }
  if (services.length === 0) return;

  const status = el('p', { class: 'text-sm text-[var(--s-fg-muted)]' });
  status.setAttribute('role', 'status');
  const uid = Math.random().toString(36).slice(2, 8);
  const labelled = (text: string, control: HTMLElement) => {
    control.id = `${uid}-${text.replace(/\W+/g, '-').toLowerCase()}`;
    return el('div', {}, el('label', { htmlFor: control.id, class: 'font-medium' }, text), control);
  };
  const serviceSelect = el('select', { class: field });
  for (const s of services)
    serviceSelect.append(
      el(
        'option',
        { value: s.id },
        `${s.name} (${s.duration} min${s.price_cents ? `, R${(s.price_cents / 100).toFixed(s.price_cents % 100 ? 2 : 0)}` : ''})`,
      ),
    );
  const memberSelect = el('select', { class: field });
  const today = new Date();
  const iso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const dateInput = el('input', {
    type: 'date',
    class: field,
    value: iso(today),
    min: iso(today),
    max: iso(new Date(today.getTime() + 60 * 864e5)),
  });
  const times = el('div', { class: 'mt-2 flex flex-wrap gap-2' });
  times.setAttribute('role', 'group');
  times.setAttribute('aria-label', 'Free times');
  let chosen: string | null = null;

  const refreshMembers = () => {
    const service = services.find((s) => s.id === serviceSelect.value);
    memberSelect.replaceChildren(
      el('option', { value: '' }, 'Anyone'),
      ...(service?.members ?? []).map((m) => el('option', { value: m.id }, m.name)),
    );
  };
  const refreshTimes = async () => {
    chosen = null;
    times.replaceChildren();
    status.textContent = 'Finding free times…';
    const q = new URLSearchParams({ service: serviceSelect.value, date: dateInput.value });
    if (memberSelect.value) q.set('member', memberSelect.value);
    const r = await fetch(`${api}/times?${q}`, { headers: { Accept: 'application/json' } }).catch(
      () => null,
    );
    const list = r?.ok
      ? ((await r.json()) as { times: { starts_at: string; label: string }[] }).times
      : [];
    for (const t of list) {
      const b = el(
        'button',
        {
          type: 'button',
          class: 'rounded-[var(--s-radius)] border border-[var(--s-border)] px-4 py-2',
        },
        t.label,
      );
      b.setAttribute('aria-pressed', 'false');
      b.addEventListener('click', () => {
        chosen = t.starts_at;
        times.querySelectorAll('button').forEach((x) => {
          x.setAttribute('aria-pressed', String(x === b));
          x.classList.toggle('bg-[var(--s-brand-soft)]', x === b);
        });
        status.textContent = `${t.label} selected.`;
      });
      times.append(b);
    }
    status.textContent = list.length
      ? `${list.length} free time${list.length === 1 ? '' : 's'}. Choose one.`
      : 'No free times on this day. Try another day.';
  };

  const name = el('input', { type: 'text', class: field, autocomplete: 'name', required: true });
  const phone = el('input', { type: 'tel', class: field, autocomplete: 'tel', required: true });
  const email = el('input', { type: 'email', class: field, autocomplete: 'email' });
  const consent = el('input', { type: 'checkbox', required: true });
  consent.id = `${uid}-consent`;
  const trap = el('input', { type: 'text', name: 'website', tabIndex: -1, autocomplete: 'off' });
  const trapWrap = el('div', { class: 'absolute -left-[9999px]' }, trap);
  trapWrap.setAttribute('aria-hidden', 'true');
  const submit = el('button', { type: 'submit', class: button }, 'Book');
  const form = el(
    'form',
    { class: 'grid gap-4' },
    labelled('Service', serviceSelect),
    labelled('With', memberSelect),
    labelled('Day', dateInput),
    times,
    labelled('Your name', name),
    labelled('Phone', phone),
    labelled('Email (optional)', email),
    el(
      'div',
      { class: 'flex items-start gap-2' },
      consent,
      el('label', { htmlFor: consent.id }, 'I agree to be contacted about my booking.'),
    ),
    trapWrap,
    submit,
    status,
  );
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!chosen) {
      status.textContent = 'Please choose a time first.';
      return;
    }
    submit.disabled = true;
    const r = await fetch(api, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({
        service: serviceSelect.value,
        member: memberSelect.value || null,
        starts_at: chosen,
        name: name.value,
        phone: phone.value,
        email: email.value || null,
        consent: consent.checked ? '1' : null,
        website: trap.value,
      }),
    }).catch(() => null);
    submit.disabled = false;
    const data = r
      ? ((await r.json().catch(() => ({}))) as {
          when?: string;
          service?: string;
          message?: string;
          errors?: Record<string, string[]>;
        })
      : {};
    if (r?.status === 201) {
      const done = el(
        'div',
        { tabIndex: -1, class: 'grid gap-2' },
        el('h3', { class: 'text-xl font-semibold' }, 'You’re booked'),
        el('p', {}, `${data.service ?? 'Your booking'} — ${data.when ?? ''}.`),
      );
      root.replaceChildren(done);
      done.focus();
      return;
    }
    status.textContent =
      data.message ??
      Object.values(data.errors ?? {})[0]?.[0] ??
      'Something went wrong. Please try again.';
    if (r?.status === 409) void refreshTimes(); // taken a moment ago: show what's still free
  });

  serviceSelect.addEventListener('change', () => {
    refreshMembers();
    void refreshTimes();
  });
  memberSelect.addEventListener('change', () => void refreshTimes());
  dateInput.addEventListener('change', () => void refreshTimes());
  refreshMembers();
  root.replaceChildren(form);
  void refreshTimes();
}

const ISLANDS: Record<string, (root: HTMLElement) => void> = {
  'before-after': beforeAfter,
  slideshow,
  promotion,
  booking: (root) => void booking(root),
};

export function startIslands(scope: ParentNode = document): void {
  scope
    .querySelectorAll<HTMLElement>('[data-island]')
    .forEach((root) => ISLANDS[root.dataset.island ?? '']?.(root));
}

if (typeof document !== 'undefined' && !(window as { __noAutoIslands?: boolean }).__noAutoIslands) {
  startIslands();
}
