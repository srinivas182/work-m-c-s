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
  location?: 'in_person' | 'online' | 'either';
  members: { id: string; name: string; registration?: string | null }[];
  /** Where it's offered, and its price there (several branches only). */
  at?: { branch: string; price_cents: number }[];
};
type BookBranch = { id: string; name: string; address: string | null };
/** A health practice: fixed, versioned consent wording for online consultations. */
type HealthTerms = { consent: string; version: string } | null;

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
  if (root.dataset.mode === 'tables') return tables(root);
  if (root.dataset.mode === 'classes') return classes(root);
  const api = root.dataset.api ?? '/book';
  let services: BookService[] = [];
  let health: HealthTerms = null;
  let branches: BookBranch[] = [];
  try {
    const r = await fetch(`${api}/services`, { headers: { Accept: 'application/json' } });
    if (!r.ok) return; // not available: keep the "call or message us" fallback
    const body = (await r.json()) as {
      services: BookService[];
      health?: HealthTerms;
      branches?: BookBranch[];
    };
    services = body.services;
    branches = body.branches ?? [];
    health = body.health ?? null;
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
  // With several branches, customers choose one first; services and prices are for that branch.
  const branchSelect = el('select', { class: field });
  for (const b of branches)
    branchSelect.append(
      el('option', { value: b.id }, b.address ? `${b.name} — ${b.address}` : b.name),
    );
  const serviceSelect = el('select', { class: field });
  const fillServices = () => {
    const keep = serviceSelect.value;
    serviceSelect.replaceChildren();
    for (const s of services) {
      const here = branches.length ? s.at?.find((a) => a.branch === branchSelect.value) : undefined;
      if (branches.length && !here) continue; // not offered at this branch
      const price = here?.price_cents ?? s.price_cents;
      serviceSelect.append(
        el(
          'option',
          { value: s.id },
          `${s.name} (${s.duration} min${price ? `, R${(price / 100).toFixed(price % 100 ? 2 : 0)}` : ''})`,
        ),
      );
    }
    if ([...serviceSelect.options].some((o) => o.value === keep)) serviceSelect.value = keep;
  };
  fillServices();
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

  // Where: a choice when the service allows either; a note when it's online only.
  const where = el('fieldset', { class: 'grid gap-1' });
  const whereOnline = el('input', { type: 'radio', name: 'where' });
  const whereInPerson = el('input', { type: 'radio', name: 'where', checked: true });
  whereOnline.id = `${uid}-where-online`;
  whereInPerson.id = `${uid}-where-in-person`;
  where.append(
    el('legend', { class: 'font-medium' }, 'Where'),
    el(
      'div',
      { class: 'flex items-center gap-2' },
      whereInPerson,
      el('label', { htmlFor: whereInPerson.id }, 'In person'),
    ),
    el(
      'div',
      { class: 'flex items-center gap-2' },
      whereOnline,
      el('label', { htmlFor: whereOnline.id }, 'Online (video call)'),
    ),
  );
  const onlineNote = el(
    'p',
    { class: 'text-sm' },
    'This is an online appointment — you’ll get a private joining link.',
  );
  const refreshWhere = () => {
    const location = services.find((s) => s.id === serviceSelect.value)?.location ?? 'in_person';
    where.hidden = location !== 'either';
    onlineNote.hidden = location !== 'online';
  };
  const healthConsent = el('input', { type: 'checkbox' });
  healthConsent.id = `${uid}-health-consent`;
  const healthBox = el(
    'div',
    {
      class: 'flex items-start gap-2 rounded-[var(--s-radius)] border border-[var(--s-border)] p-3',
    },
    healthConsent,
    el('label', { htmlFor: healthConsent.id }, health?.consent ?? ''),
  );
  const refreshHealth = () => {
    const needed = health !== null && isOnline();
    healthBox.hidden = !needed;
    healthConsent.required = needed;
  };
  const isOnline = () => {
    const location = services.find((s) => s.id === serviceSelect.value)?.location ?? 'in_person';
    return location === 'online' || (location === 'either' && whereOnline.checked);
  };
  const refreshMembers = () => {
    const service = services.find((s) => s.id === serviceSelect.value);
    memberSelect.replaceChildren(
      el('option', { value: '' }, 'Anyone'),
      ...(service?.members ?? []).map((m) =>
        el('option', { value: m.id }, m.registration ? `${m.name} — ${m.registration}` : m.name),
      ),
    );
  };
  const refreshTimes = async () => {
    chosen = null;
    times.replaceChildren();
    status.textContent = 'Finding free times…';
    const q = new URLSearchParams({ service: serviceSelect.value, date: dateInput.value });
    if (memberSelect.value) q.set('member', memberSelect.value);
    if (branches.length) q.set('branch', branchSelect.value);
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
    ...(branches.length ? [labelled('Branch', branchSelect)] : []),
    labelled('Service', serviceSelect),
    labelled('With', memberSelect),
    where,
    onlineNote,
    labelled('Day', dateInput),
    times,
    labelled('Your name', name),
    labelled('Phone', phone),
    labelled('Email (optional)', email),
    healthBox,
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
        branch: branches.length ? branchSelect.value : null,
        member: memberSelect.value || null,
        online: isOnline(),
        health_consent: health !== null && isOnline() ? healthConsent.checked : null,
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
    if (r?.status === 201 && (data as { pay_url?: string }).pay_url) {
      status.textContent = 'Taking you to pay…';
      window.location.assign((data as { pay_url: string }).pay_url); // Paystack's secure checkout
      return;
    }
    if (r?.status === 201) {
      const done = el(
        'div',
        { tabIndex: -1, class: 'grid gap-2' },
        el('h3', { class: 'text-xl font-semibold' }, 'You’re booked'),
        el('p', {}, `${data.service ?? 'Your booking'} — ${data.when ?? ''}.`),
        ...((data as { manage_url?: string }).manage_url
          ? [
              el(
                'a',
                { href: (data as { manage_url: string }).manage_url, class: 'underline' },
                'Change or cancel this booking',
              ),
            ]
          : []),
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
    refreshWhere();
    refreshHealth();
    refreshMembers();
    void refreshTimes();
  });
  memberSelect.addEventListener('change', () => void refreshTimes());
  dateInput.addEventListener('change', () => void refreshTimes());
  branchSelect.addEventListener('change', () => {
    fillServices();
    refreshWhere();
    refreshHealth();
    refreshMembers();
    void refreshTimes();
  });
  refreshMembers();
  refreshWhere();
  refreshHealth();
  where.addEventListener('change', refreshHealth);
  root.replaceChildren(form);
  void refreshTimes();
}

/* Tables: guests → day → free time → details → confirmation (restaurants). Same safeguards as booking. */
async function tables(root: HTMLElement): Promise<void> {
  const api = `${root.dataset.api ?? '/book'}/tables`;
  const today = new Date();
  const iso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const first = await fetch(`${api}/times?date=${iso(today)}&party=2`, {
    headers: { Accept: 'application/json' },
  }).catch(() => null);
  if (!first?.ok) return; // tables not taken online: keep the "call or message us" fallback
  const maxParty = ((await first.json()) as { max_party: number | null }).max_party ?? 8;

  const status = el('p', { class: 'text-sm text-[var(--s-fg-muted)]' });
  status.setAttribute('role', 'status');
  const uid = Math.random().toString(36).slice(2, 8);
  const labelled = (text: string, control: HTMLElement) => {
    control.id = `${uid}-${text.replace(/\W+/g, '-').toLowerCase()}`;
    return el('div', {}, el('label', { htmlFor: control.id, class: 'font-medium' }, text), control);
  };
  const party = el('select', { class: field });
  for (let n = 1; n <= maxParty; n++)
    party.append(
      el('option', { value: String(n), selected: n === 2 }, `${n} guest${n === 1 ? '' : 's'}`),
    );
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

  const refreshTimes = async () => {
    chosen = null;
    times.replaceChildren();
    status.textContent = 'Finding free times…';
    const r = await fetch(`${api}/times?date=${dateInput.value}&party=${party.value}`, {
      headers: { Accept: 'application/json' },
    }).catch(() => null);
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
      : 'No free tables on this day for that many guests. Try another day.';
  };

  const name = el('input', { type: 'text', class: field, autocomplete: 'name', required: true });
  const phone = el('input', { type: 'tel', class: field, autocomplete: 'tel', required: true });
  const email = el('input', { type: 'email', class: field, autocomplete: 'email' });
  const consent = el('input', { type: 'checkbox', required: true });
  consent.id = `${uid}-consent`;
  const trap = el('input', { type: 'text', name: 'website', tabIndex: -1, autocomplete: 'off' });
  const trapWrap = el('div', { class: 'absolute -left-[9999px]' }, trap);
  trapWrap.setAttribute('aria-hidden', 'true');
  const submit = el('button', { type: 'submit', class: button }, 'Reserve');
  const form = el(
    'form',
    { class: 'grid gap-4' },
    labelled('Guests', party),
    labelled('Day', dateInput),
    times,
    labelled('Your name', name),
    labelled('Phone', phone),
    labelled('Email (optional)', email),
    el(
      'div',
      { class: 'flex items-start gap-2' },
      consent,
      el('label', { htmlFor: consent.id }, 'I agree to be contacted about my reservation.'),
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
        starts_at: chosen,
        party: Number(party.value),
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
          manage_url?: string;
          errors?: Record<string, string[]>;
        })
      : {};
    if (r?.status === 201) {
      const done = el(
        'div',
        { tabIndex: -1, class: 'grid gap-2' },
        el('h3', { class: 'text-xl font-semibold' }, 'You’re booked'),
        el('p', {}, `${data.service ?? 'Your table'} — ${data.when ?? ''}.`),
        ...(data.manage_url
          ? [
              el(
                'a',
                { href: data.manage_url, class: 'underline' },
                'Change or cancel this reservation',
              ),
            ]
          : []),
      );
      root.replaceChildren(done);
      done.focus();
      return;
    }
    status.textContent =
      data.message ??
      Object.values(data.errors ?? {})[0]?.[0] ??
      'Something went wrong. Please try again.';
    if (r?.status === 409) void refreshTimes();
  });
  party.addEventListener('change', () => void refreshTimes());
  dateInput.addEventListener('change', () => void refreshTimes());
  root.replaceChildren(form);
  void refreshTimes();
}

/* Classes: upcoming class → details → confirmation. Same safeguards as booking. */
async function classes(root: HTMLElement): Promise<void> {
  const api = `${root.dataset.api ?? '/book'}/classes`;
  const first = await fetch(api, { headers: { Accept: 'application/json' } }).catch(() => null);
  if (!first?.ok) return; // keep the "call or message us" fallback
  type ClassItem = {
    id: string;
    title: string;
    online: boolean;
    when: string;
    minutes: number;
    price_cents: number;
    places_left: number;
    registration?: string | null;
  };
  const firstBody = (await first.json()) as { classes: ClassItem[]; health?: HealthTerms };
  let list = firstBody.classes;
  const health = firstBody.health ?? null;
  const healthConsent = el('input', { type: 'checkbox' });
  const healthBox = el(
    'div',
    {
      class: 'flex items-start gap-2 rounded-[var(--s-radius)] border border-[var(--s-border)] p-3',
    },
    healthConsent,
  );
  const refreshHealth = () => {
    const needed = health !== null && list.find((c) => c.id === chosen)?.online === true;
    healthBox.hidden = !needed;
    healthConsent.required = needed;
  };

  const status = el('p', { class: 'text-sm text-[var(--s-fg-muted)]' });
  status.setAttribute('role', 'status');
  const uid = Math.random().toString(36).slice(2, 8);
  const labelled = (text: string, control: HTMLElement) => {
    control.id = `${uid}-${text.replace(/\W+/g, '-').toLowerCase()}`;
    return el('div', {}, el('label', { htmlFor: control.id, class: 'font-medium' }, text), control);
  };
  const picker = el('div', { class: 'grid gap-2' });
  picker.setAttribute('role', 'group');
  picker.setAttribute('aria-label', 'Upcoming classes');
  let chosen: string | null = null;
  const draw = () => {
    picker.replaceChildren();
    if (list.length === 0) {
      status.textContent = 'No classes in the next two weeks. Please check back soon.';
      return;
    }
    for (const c of list) {
      const full = c.places_left < 1;
      const price = c.price_cents
        ? ` · R${(c.price_cents / 100).toFixed(c.price_cents % 100 ? 2 : 0)}`
        : '';
      const b = el(
        'button',
        {
          type: 'button',
          disabled: full,
          class: 'rounded-[var(--s-radius)] border border-[var(--s-border)] px-4 py-2 text-left',
        },
        `${c.title} — ${c.when} · ${c.minutes} min${c.online ? ' · Online' : ''}${c.registration ? ` · ${c.registration}` : ''}${price} · ${full ? 'Full' : `${c.places_left} place${c.places_left === 1 ? '' : 's'} left`}`,
      );
      b.setAttribute('aria-pressed', String(chosen === c.id));
      b.addEventListener('click', () => {
        chosen = c.id;
        picker.querySelectorAll('button').forEach((x) => {
          x.setAttribute('aria-pressed', String(x === b));
          x.classList.toggle('bg-[var(--s-brand-soft)]', x === b);
        });
        status.textContent = `${c.title} selected.`;
        refreshHealth();
      });
      picker.append(b);
    }
  };
  const refresh = async () => {
    const r = await fetch(api, { headers: { Accept: 'application/json' } }).catch(() => null);
    list = r?.ok ? ((await r.json()) as { classes: ClassItem[] }).classes : [];
    chosen = null;
    draw();
    refreshHealth();
  };

  const name = el('input', { type: 'text', class: field, autocomplete: 'name', required: true });
  const phone = el('input', { type: 'tel', class: field, autocomplete: 'tel', required: true });
  const email = el('input', { type: 'email', class: field, autocomplete: 'email' });
  const consent = el('input', { type: 'checkbox', required: true });
  consent.id = `${uid}-consent`;
  healthConsent.id = `${uid}-health-consent`;
  healthBox.append(el('label', { htmlFor: healthConsent.id }, health?.consent ?? ''));
  const trap = el('input', { type: 'text', name: 'website', tabIndex: -1, autocomplete: 'off' });
  const trapWrap = el('div', { class: 'absolute -left-[9999px]' }, trap);
  trapWrap.setAttribute('aria-hidden', 'true');
  const submit = el('button', { type: 'submit', class: button }, 'Book my place');
  const form = el(
    'form',
    { class: 'grid gap-4' },
    picker,
    labelled('Your name', name),
    labelled('Phone', phone),
    labelled('Email (optional)', email),
    healthBox,
    el(
      'div',
      { class: 'flex items-start gap-2' },
      consent,
      el('label', { htmlFor: consent.id }, 'I agree to be contacted about my class.'),
    ),
    trapWrap,
    submit,
    status,
  );
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!chosen) {
      status.textContent = 'Please choose a class first.';
      return;
    }
    submit.disabled = true;
    const r = await fetch(`${api}/${chosen}`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: name.value,
        phone: phone.value,
        email: email.value || null,
        consent: consent.checked ? '1' : null,
        health_consent: healthBox.hidden ? null : healthConsent.checked,
        website: trap.value,
      }),
    }).catch(() => null);
    submit.disabled = false;
    const data = r
      ? ((await r.json().catch(() => ({}))) as {
          when?: string;
          service?: string;
          message?: string;
          manage_url?: string;
          errors?: Record<string, string[]>;
        })
      : {};
    if (r?.status === 201 && (data as { pay_url?: string }).pay_url) {
      status.textContent = 'Taking you to pay…';
      window.location.assign((data as { pay_url: string }).pay_url); // Paystack's secure checkout
      return;
    }
    if (r?.status === 201) {
      const done = el(
        'div',
        { tabIndex: -1, class: 'grid gap-2' },
        el('h3', { class: 'text-xl font-semibold' }, 'You’re booked'),
        el('p', {}, `${data.service ?? 'Your class'} — ${data.when ?? ''}.`),
        ...(data.manage_url
          ? [el('a', { href: data.manage_url, class: 'underline' }, 'Change or cancel this place')]
          : []),
      );
      root.replaceChildren(done);
      done.focus();
      return;
    }
    status.textContent =
      data.message ??
      Object.values(data.errors ?? {})[0]?.[0] ??
      'Something went wrong. Please try again.';
    if (r?.status === 409) void refresh();
  });
  root.replaceChildren(form);
  draw();
  refreshHealth(); // hidden until an online class is chosen
}

/* ---------- Shop: the cart lives in the shopper's browser; the server re-checks every price and stock level. ---------- */
type CartLine = { variant: string; quantity: number };
const CART_KEY = 'wurk-cart';
const readCart = (): CartLine[] => {
  try {
    const raw = JSON.parse(localStorage.getItem(CART_KEY) ?? '[]') as unknown;
    return Array.isArray(raw)
      ? (raw as CartLine[]).filter((l) => typeof l?.variant === 'string' && Number(l.quantity) > 0)
      : [];
  } catch {
    return [];
  }
};
const writeCart = (lines: CartLine[]) => {
  try {
    if (lines.length) localStorage.setItem(CART_KEY, JSON.stringify(lines));
    else localStorage.removeItem(CART_KEY);
  } catch {
    /* storage blocked: the cart lasts this page only */
  }
};
const randOf = (c: number) => `R${(c / 100).toFixed(2)}`;

/* Add to cart (product pages). */
function addToCart(root: HTMLElement): void {
  const variants = JSON.parse(root.dataset.variants ?? '[]') as {
    id: string;
    label: string | null;
    price_cents: number;
    in_stock: boolean;
  }[];
  if (!variants.some((v) => v.in_stock)) {
    root.replaceChildren(el('p', { class: 'font-semibold' }, 'Sold out'));
    return;
  }
  const uid = Math.random().toString(36).slice(2, 8);
  const status = el('p', { class: 'text-sm' });
  status.setAttribute('role', 'status');
  const choose = el('select', { class: field });
  for (const v of variants)
    choose.append(
      el(
        'option',
        { value: v.id, disabled: !v.in_stock },
        `${v.label ?? root.dataset.product ?? ''} — ${randOf(v.price_cents)}${v.in_stock ? '' : ' (sold out)'}`,
      ),
    );
  choose.value = variants.find((v) => v.in_stock)?.id ?? '';
  choose.id = `${uid}-variant`;
  const qty = el('input', { type: 'number', min: '1', max: '50', value: '1', class: field });
  qty.id = `${uid}-qty`;
  const add = el('button', { type: 'submit', class: button }, 'Add to cart');
  const form = el('form', { class: 'grid gap-3' });
  if (variants.length > 1)
    form.append(
      el('div', {}, el('label', { htmlFor: choose.id, class: 'font-medium' }, 'Choose'), choose),
    );
  form.append(
    el(
      'div',
      { class: 'max-w-32' },
      el('label', { htmlFor: qty.id, class: 'font-medium' }, 'Quantity'),
      qty,
    ),
    add,
    status,
  );
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const quantity = Math.min(50, Math.max(1, Number(qty.value) || 1));
    const cart = readCart();
    const line = cart.find((l) => l.variant === choose.value);
    if (line) line.quantity = Math.min(50, line.quantity + quantity);
    else cart.push({ variant: choose.value, quantity });
    writeCart(cart);
    const count = cart.reduce((n, l) => n + l.quantity, 0);
    status.replaceChildren(
      `Added to your cart (${count} item${count === 1 ? '' : 's'}). `,
      el('a', { href: '/cart', class: 'font-semibold underline' }, 'View cart and check out'),
    );
  });
  root.replaceChildren(form);
}

/* The cart and checkout (/cart). */
async function checkout(root: HTMLElement): Promise<void> {
  type Priced = {
    variant: string;
    name: string;
    label: string | null;
    slug: string;
    unit_cents: number;
    quantity: number;
    available: number;
  };
  type Settings = {
    available: boolean;
    card?: boolean;
    offline?: boolean;
    collection: boolean;
    collection_address: string | null;
    delivery: boolean;
    delivery_fee_cents: number;
    delivery_note: string | null;
    branches?: { id: string; name: string; address: string | null }[]; // several branches: where to collect
    branch?: string;
  };
  let picked: { branch: string | null; fulfilment: string } = {
    branch: null,
    fulfilment: 'collection',
  };
  const uid = Math.random().toString(36).slice(2, 8);
  const status = el('p', { class: 'text-sm' });
  status.setAttribute('role', 'status');
  const empty = () =>
    root.replaceChildren(
      el('p', {}, 'Your cart is empty.'),
      el('a', { href: '/', class: 'font-semibold underline' }, 'Continue shopping'),
    );

  const price = async (): Promise<{ lines: Priced[]; checkout: Settings } | null> => {
    const r = await fetch('/cart/price', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({
        lines: readCart(),
        branch: picked.branch,
        fulfilment: picked.fulfilment,
      }), // stock and prices at the branch that fulfils it
    }).catch(() => null);
    return r?.ok ? ((await r.json()) as { lines: Priced[]; checkout: Settings }) : null;
  };
  let data = readCart().length ? await price() : null;
  if (!data || data.lines.length === 0) {
    writeCart([]);
    empty();
    return;
  }
  writeCart(
    data.lines.map((l) => ({
      variant: l.variant,
      quantity: Math.min(l.quantity, Math.max(1, l.available)),
    })),
  ); // drop what's gone
  if (!data.checkout.available) {
    root.replaceChildren(
      el('p', {}, 'Ordering online isn’t available right now. Please contact us to order.'),
    );
    return;
  }

  const list = el('ul', { class: 'grid gap-3' });
  list.setAttribute('aria-label', 'Items in your cart');
  const totals = el('p', { class: 'text-lg font-semibold' });
  const id = (name: string) => `${uid}-${name}`;
  const radio = (value: string, text: string, checked: boolean) => {
    const input = el('input', { type: 'radio', name: `${uid}-how`, value, checked });
    input.id = id(value);
    return {
      input,
      row: el(
        'div',
        { class: 'flex items-start gap-2' },
        input,
        el('label', { htmlFor: input.id }, text),
      ),
    };
  };
  const s = data.checkout;
  const collect = radio(
    'collection',
    `Collect${s.collection_address ? ` — ${s.collection_address}` : ''}`,
    s.collection,
  );
  const deliver = radio(
    'delivery',
    `Delivery — ${randOf(s.delivery_fee_cents)}${s.delivery_note ? ` (${s.delivery_note})` : ''}`,
    !s.collection,
  );
  const how = el(
    'fieldset',
    { class: 'grid gap-2' },
    el('legend', { class: 'font-medium' }, 'How you’ll get it'),
  );
  if (s.collection) how.append(collect.row);
  if (s.delivery) how.append(deliver.row);
  const input = (name: string, type: string, extra: Partial<HTMLInputElement> = {}) => {
    const i = el('input', { type, class: field, ...extra });
    i.id = id(name);
    return i;
  };
  const labelled = (text: string, control: HTMLElement) =>
    el('div', {}, el('label', { htmlFor: control.id, class: 'font-medium' }, text), control);
  const name = input('name', 'text', { autocomplete: 'name', required: true });
  const phone = input('phone', 'tel', { autocomplete: 'tel', required: true });
  const email = input('email', 'email', { autocomplete: 'email' });
  const address = el('textarea', { class: field, rows: 3 });
  address.id = id('address');
  const addressRow = labelled('Delivery address', address);
  const notes = input('notes', 'text');
  const consent = el('input', { type: 'checkbox', required: true });
  consent.id = id('consent');
  const trap = el('input', { type: 'text', name: 'website', tabIndex: -1, autocomplete: 'off' });
  const trapWrap = el('div', { class: 'absolute -left-[9999px]' }, trap);
  trapWrap.setAttribute('aria-hidden', 'true');
  const place = el('button', { type: 'submit', class: button }, 'Place order');

  const payCard = el('input', { type: 'radio', name: `${uid}-pay`, value: 'card', checked: true });
  payCard.id = id('pay-card');
  const payEft = el('input', { type: 'radio', name: `${uid}-pay`, value: 'offline' });
  payEft.id = id('pay-eft');
  const pay = el(
    'fieldset',
    { class: 'grid gap-2' },
    el('legend', { class: 'font-medium' }, 'How you’ll pay'),
    el(
      'div',
      { class: 'flex items-start gap-2' },
      payCard,
      el('label', { htmlFor: payCard.id }, 'Card — pay now, securely'),
    ),
    el(
      'div',
      { class: 'flex items-start gap-2' },
      payEft,
      el('label', { htmlFor: payEft.id }, 'EFT or on collection — we’ll show you how'),
    ),
  );
  pay.hidden = !(s.card && s.offline); // a choice only when both are on
  const payment = () =>
    s.card && s.offline ? (payCard.checked ? 'card' : 'offline') : s.card ? 'card' : 'offline';
  const delivering = () => s.delivery && (!s.collection || deliver.input.checked);
  const redraw = () => {
    if (!data) return;
    const cur = data; // checked once; the handlers below use it
    list.replaceChildren();
    let subtotal = 0;
    for (const line of cur.lines) {
      subtotal += line.unit_cents * line.quantity;
      const q = el('input', {
        type: 'number',
        min: '1',
        max: String(Math.max(1, line.available)),
        value: String(line.quantity),
        class: `${field} max-w-24`,
      });
      q.id = id(`qty-${line.variant}`);
      const what = `${line.name}${line.label ? ` (${line.label})` : ''}`;
      q.addEventListener('change', () => {
        line.quantity = Math.min(Math.max(1, line.available), Math.max(1, Number(q.value) || 1));
        writeCart(cur.lines.map((l) => ({ variant: l.variant, quantity: l.quantity })));
        redraw();
      });
      const remove = el('button', { type: 'button', class: 'underline' }, 'Remove');
      remove.setAttribute('aria-label', `Remove ${what}`);
      remove.addEventListener('click', () => {
        cur.lines = cur.lines.filter((l) => l !== line);
        writeCart(cur.lines.map((l) => ({ variant: l.variant, quantity: l.quantity })));
        if (cur.lines.length === 0) empty();
        else redraw();
      });
      list.append(
        el(
          'li',
          {
            class:
              'flex flex-wrap items-end justify-between gap-3 border-b border-[var(--s-border)] pb-3',
          },
          el(
            'div',
            {},
            el('a', { href: `/shop/${line.slug}`, class: 'font-semibold underline' }, what),
            el(
              'p',
              { class: 'text-sm' },
              `${randOf(line.unit_cents)} each${line.available === 0 ? ' — sold out' : ''}`,
            ),
          ),
          el(
            'div',
            { class: 'flex items-end gap-3' },
            el('div', {}, el('label', { htmlFor: q.id, class: 'text-sm' }, 'Quantity'), q),
            remove,
          ),
        ),
      );
    }
    const fee = delivering() ? s.delivery_fee_cents : 0;
    addressRow.hidden = !delivering();
    totals.textContent = `Total ${randOf(subtotal + fee)} (incl. VAT${fee ? `, delivery ${randOf(fee)}` : ''})`;
  };
  // Several branches: customers choose where to collect from; stock and prices follow the branch.
  const branchPick = el('select', { class: field });
  for (const b of s.branches ?? [])
    branchPick.append(
      el('option', { value: b.id }, b.address ? `${b.name} — ${b.address}` : b.name),
    );
  if (s.branch) branchPick.value = s.branch;
  branchPick.id = id('collect-from');
  const branchRow = el(
    'div',
    {},
    el('label', { htmlFor: branchPick.id, class: 'font-medium' }, 'Collect from'),
    branchPick,
  );
  const several = (s.branches ?? []).length > 1;
  const reprice = async () => {
    picked = {
      branch: delivering() ? null : several ? branchPick.value : null,
      fulfilment: delivering() ? 'delivery' : 'collection',
    };
    branchRow.hidden = !several || delivering();
    const fresh = await price();
    if (fresh && fresh.lines.length) data = fresh;
    redraw();
  };
  branchPick.addEventListener('change', () => void reprice());
  collect.input.addEventListener('change', () => void reprice());
  deliver.input.addEventListener('change', () => void reprice());
  branchRow.hidden = !several || delivering();

  const form = el(
    'form',
    { class: 'grid gap-4' },
    list,
    how,
    branchRow,
    pay,
    totals,
    labelled('Your name', name),
    labelled('Phone', phone),
    labelled('Email (for your receipt, optional)', email),
    addressRow,
    labelled('Notes (optional)', notes),
    el(
      'div',
      { class: 'flex items-start gap-2' },
      consent,
      el('label', { htmlFor: consent.id }, 'I agree to be contacted about my order.'),
    ),
    trapWrap,
    place,
    status,
  );
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    place.disabled = true;
    status.textContent = 'Placing your order…';
    const r = await fetch('/cart/checkout', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({
        lines: readCart(),
        name: name.value,
        phone: phone.value,
        email: email.value || null,
        fulfilment: delivering() ? 'delivery' : 'collection',
        branch: !delivering() && several ? branchPick.value : null,
        address: delivering() ? address.value : null,
        notes: notes.value || null,
        payment: payment(),
        consent: consent.checked ? '1' : null,
        website: trap.value,
      }),
    }).catch(() => null);
    place.disabled = false;
    const body = r
      ? ((await r.json().catch(() => ({}))) as {
          order_url?: string;
          pay_url?: string;
          message?: string;
          errors?: Record<string, string[]>;
        })
      : {};
    if (r?.status === 201 && body.pay_url) {
      writeCart([]);
      status.textContent = 'Order placed. Taking you to pay…';
      window.location.assign(body.pay_url); // Yoco or PayFast; they bring the customer back to the order page
      return;
    }
    if (r?.status === 201 && body.order_url) {
      writeCart([]);
      status.textContent = 'Order placed. Taking you to your order…';
      window.location.assign(body.order_url);
      return;
    }
    status.textContent =
      body.message ??
      Object.values(body.errors ?? {})[0]?.[0] ??
      'Something went wrong. Please try again.';
    if (r?.status === 409) {
      data = await price(); // stock changed: show what's available now
      if (data && data.lines.length) redraw();
    }
  });
  root.replaceChildren(form);
  redraw();
}

const ISLANDS: Record<string, (root: HTMLElement) => void> = {
  'before-after': beforeAfter,
  slideshow,
  promotion,
  booking: (root) => void booking(root),
  'add-to-cart': addToCart,
  checkout: (root) => void checkout(root),
};

export function startIslands(scope: ParentNode = document): void {
  scope
    .querySelectorAll<HTMLElement>('[data-island]')
    .forEach((root) => ISLANDS[root.dataset.island ?? '']?.(root));
}

if (typeof document !== 'undefined' && !(window as { __noAutoIslands?: boolean }).__noAutoIslands) {
  startIslands();
}
