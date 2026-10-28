// @vitest-environment jsdom
import { renderToString } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { BLOCKS } from '@modules/Website/resources/js/site/registry';
import { SiteRenderer } from '@modules/Website/resources/js/site/SiteRenderer';
import type { Branding, ContentDocument } from '@modules/Website/resources/js/site/types';

(window as { __noAutoIslands?: boolean }).__noAutoIslands = true;
const { startIslands } = await import('./site-islands');

const branding: Branding = {
  primary: '#9D174D',
  secondary: null,
  font_pairing: 'classic',
  corners: 'soft',
  logo: null,
  favicon: null,
};
/** Exactly what a published page carries: server-rendered HTML, no React running. */
function publish(type: string, props: Record<string, unknown>) {
  const def = BLOCKS[type]!;
  const blocks: ContentDocument['blocks'] = [
    { id: 'b1', type, version: def.version, props: { ...def.defaults, ...props } },
  ];
  document.body.innerHTML = renderToString(
    <SiteRenderer
      document={{ schema: 1, blocks }}
      branding={branding}
      site={{ businessName: 'Glow Salon', editing: false, menus: { header: [], footer: [] } }}
    />,
  );
  startIslands(document);
}
const photos = [1, 2, 3].map((n) => ({
  src: `/storage/sites/t/p${n}-1600.webp`,
  alt: `Photo ${n}`,
}));

beforeEach(() => {
  vi.useRealTimers();
  window.matchMedia = ((q: string) => ({
    matches: false,
    media: q,
    addEventListener() {},
    removeEventListener() {},
  })) as never;
  HTMLElement.prototype.scrollTo = vi.fn();
});

describe('islands on published pages', () => {
  it('before & after: the slider moves the comparison', () => {
    publish('BeforeAfter', {
      layout: 'slider',
      items: [
        { before: '/b-1600.webp', beforeAlt: 'Before', after: '/a-1600.webp', afterAlt: 'After' },
      ],
    });
    const range = document.querySelector<HTMLInputElement>('[data-ba-range]')!;
    range.value = '20';
    range.dispatchEvent(new Event('input'));
    expect(document.querySelector<HTMLElement>('[data-ba-clip]')!.style.clipPath).toBe(
      'inset(0 80% 0 0)',
    );
    expect(document.querySelector<HTMLElement>('[data-ba-line]')!.style.left).toBe('20%');
  });

  it('slideshow: dots and arrows move and mark the photo; pause swaps label and icon; autoplay advances', () => {
    vi.useFakeTimers();
    publish('Hero', { layout: 'slideshow', images: photos, autoplay: true });
    const dots = [...document.querySelectorAll<HTMLButtonElement>('[data-hs-dot]')];
    expect(dots).toHaveLength(3);

    dots[2]!.click();
    expect(dots[2]!.getAttribute('aria-current')).toBe('true');
    document.querySelector<HTMLButtonElement>('[data-hs-next]')!.click(); // wraps round
    expect(dots[0]!.getAttribute('aria-current')).toBe('true');

    vi.advanceTimersByTime(7000);
    expect(dots[1]!.getAttribute('aria-current')).toBe('true');

    const pause = document.querySelector<HTMLButtonElement>('[data-hs-pause]')!;
    expect(pause.hidden).toBe(false); // visible whenever the slideshow moves (WCAG 2.2.2)
    pause.click();
    expect(pause.getAttribute('aria-label')).toBe('Play slideshow');
    expect(pause.querySelector('[data-hs-icon="pause"]')!.classList.contains('hidden')).toBe(true);
    vi.advanceTimersByTime(14000);
    expect(dots[1]!.getAttribute('aria-current')).toBe('true'); // paused: no movement
  });

  it('slideshow: never autoplays for visitors who ask for reduced motion', () => {
    vi.useFakeTimers();
    window.matchMedia = ((q: string) => ({
      matches: q.includes('reduce'),
      media: q,
      addEventListener() {},
      removeEventListener() {},
    })) as never;
    publish('Hero', { layout: 'slideshow', images: photos, autoplay: true });
    vi.advanceTimersByTime(21000);
    expect(document.querySelector('[data-hs-dot="0"]')!.getAttribute('aria-current')).toBe('true');
    expect(document.querySelector<HTMLButtonElement>('[data-hs-pause]')?.hidden ?? true).toBe(true);
  });

  it('promotion: close removes the banner', () => {
    publish('PromotionBanner', {});
    document.querySelector<HTMLButtonElement>('[data-promo-close]')!.click();
    expect(document.querySelector('[data-island="promotion"]')).toBeNull();
  });
});

describe('booking island', () => {
  const services = {
    services: [
      {
        id: 's1',
        name: 'Knotless braids',
        duration: 60,
        price_cents: 45000,
        members: [{ id: 'm1', name: 'Zanele' }],
      },
    ],
  };
  const times = {
    times: [
      { starts_at: '2027-05-03T07:00:00+00:00', label: '09:00' },
      { starts_at: '2027-05-03T08:00:00+00:00', label: '10:00' },
    ],
  };
  const respond = (status: number, body: unknown) =>
    Promise.resolve(
      new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
  const mount = () => {
    document.body.innerHTML =
      '<div data-island="booking" data-api="/book"><p data-booking-fallback>Call or message us to book. <a href="/contact">Contact us</a></p></div>';
    startIslands(document);
  };
  const settle = () =>
    new Promise((r) => setTimeout(r, 0)).then(() => new Promise((r) => setTimeout(r, 0)));

  it('books: service → time → details → confirmation (focused)', async () => {
    const fetchMock = vi.fn((url: string, init?: RequestInit) => {
      if (url === '/book/services') return respond(200, services);
      if (url.startsWith('/book/times')) return respond(200, times);
      return respond(201, {
        booked: true,
        service: 'Knotless braids',
        when: 'Monday 3 May, 09:00',
        sent: JSON.parse(String(init?.body)),
      });
    });
    vi.stubGlobal('fetch', fetchMock);
    mount();
    await settle();
    expect(document.querySelector('[data-booking-fallback]')).toBeNull();
    const group = document.querySelector('[role="group"][aria-label="Free times"]')!;
    expect([...group.querySelectorAll('button')].map((b) => b.textContent)).toEqual([
      '09:00',
      '10:00',
    ]);
    (group.querySelector('button') as HTMLButtonElement).click();
    expect(group.querySelector('button')!.getAttribute('aria-pressed')).toBe('true');

    const byLabel = (text: string) =>
      document.getElementById(
        [...document.querySelectorAll('label')].find((l) => l.textContent === text)!.htmlFor,
      ) as HTMLInputElement;
    byLabel('Your name').value = 'Sipho';
    byLabel('Phone').value = '082 555 0101';
    (document.querySelector('input[type="checkbox"]') as HTMLInputElement).checked = true;
    document.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));
    await settle();

    const sent = JSON.parse(String(fetchMock.mock.calls.at(-1)![1]!.body));
    expect(sent).toMatchObject({
      service: 's1',
      starts_at: '2027-05-03T07:00:00+00:00',
      name: 'Sipho',
      consent: '1',
      website: '',
    });
    expect(document.querySelector('h3')!.textContent).toBe('You’re booked');
    expect(document.activeElement?.textContent).toContain('Monday 3 May, 09:00');
    vi.unstubAllGlobals();
  });

  it('says so when the time was just taken, and shows what is still free', async () => {
    let timesCalls = 0;
    vi.stubGlobal(
      'fetch',
      vi.fn((url: string) => {
        if (url === '/book/services') return respond(200, services);
        if (url.startsWith('/book/times')) return (timesCalls++, respond(200, times));
        return respond(409, {
          message: 'Sorry — that time has just been taken. Please choose another.',
        });
      }),
    );
    mount();
    await settle();
    (document.querySelector('[aria-label="Free times"] button') as HTMLButtonElement).click();
    document.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));
    await settle();
    expect(document.querySelector('[role="status"]')!.textContent).toMatch(
      /free times|just been taken/,
    );
    expect(timesCalls).toBe(2); // refreshed after the refusal
    vi.unstubAllGlobals();
  });

  it('lets the client choose online when the service allows either, and notes online-only services', async () => {
    const two = {
      services: [
        {
          id: 's1',
          name: 'Coaching',
          duration: 60,
          price_cents: 0,
          location: 'either',
          members: [{ id: 'm1', name: 'Thandi' }],
        },
        {
          id: 's2',
          name: 'Video check-in',
          duration: 30,
          price_cents: 0,
          location: 'online',
          members: [{ id: 'm1', name: 'Thandi' }],
        },
      ],
    };
    const fetchMock = vi.fn<(url: string, init?: RequestInit) => Promise<Response>>((url) => {
      if (url === '/book/services') return respond(200, two);
      if (url.startsWith('/book/times')) return respond(200, times);
      return respond(201, { booked: true, service: 'Coaching', when: 'Monday 3 May, 09:00' });
    });
    vi.stubGlobal('fetch', fetchMock);
    mount();
    await settle();
    const where = document.querySelector('fieldset') as HTMLFieldSetElement;
    const note = [...document.querySelectorAll('p')].find((p) =>
      p.textContent?.startsWith('This is an online appointment'),
    )!;
    expect(where.hidden).toBe(false);
    expect(note.hidden).toBe(true);
    const labelled = (text: string) =>
      document.getElementById(
        [...document.querySelectorAll('label')].find((l) => l.textContent === text)!.htmlFor,
      ) as HTMLInputElement;
    expect(labelled('In person').checked).toBe(true); // in person unless the client chooses online
    labelled('Online (video call)').click();

    (
      document.querySelector('[role="group"][aria-label="Free times"] button') as HTMLButtonElement
    ).click();
    labelled('Your name').value = 'Sipho';
    labelled('Phone').value = '082 555 0101';
    (document.querySelector('input[type="checkbox"]') as HTMLInputElement).checked = true;
    document.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));
    await settle();
    expect(JSON.parse(String(fetchMock.mock.calls.at(-1)![1]!.body))).toMatchObject({
      service: 's1',
      online: true,
    });

    mount();
    await settle();
    const select = [...document.querySelectorAll('select')][0] as HTMLSelectElement;
    select.value = 's2';
    select.dispatchEvent(new Event('change'));
    expect((document.querySelector('fieldset') as HTMLFieldSetElement).hidden).toBe(true);
    expect(
      [...document.querySelectorAll('p')].find((p) =>
        p.textContent?.startsWith('This is an online appointment'),
      )!.hidden,
    ).toBe(false);
    vi.unstubAllGlobals();
  });

  it('keeps the “call or message us” fallback when booking isn’t available', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => respond(404, {})),
    );
    mount();
    await settle();
    expect(document.querySelector('[data-booking-fallback]')).not.toBeNull();
    vi.unstubAllGlobals();
  });
});

describe('checkout island', () => {
  const respond = (status: number, body: unknown) =>
    Promise.resolve(
      new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
  const settle = () =>
    new Promise((r) => setTimeout(r, 0)).then(() => new Promise((r) => setTimeout(r, 0)));
  const priced = (card: boolean, offline: boolean) => ({
    lines: [
      {
        variant: 'v1',
        name: 'Linen dress',
        label: 'M',
        slug: 'linen-dress',
        unit_cents: 89900,
        quantity: 1,
        available: 5,
      },
    ],
    subtotal_cents: 89900,
    checkout: {
      available: true,
      card,
      offline,
      collection: true,
      collection_address: '12 Demo St',
      delivery: false,
      delivery_fee_cents: 0,
      delivery_note: null,
    },
  });
  const mount = () => {
    localStorage.setItem('wurk-cart', JSON.stringify([{ variant: 'v1', quantity: 1 }]));
    document.body.innerHTML = '<div data-island="checkout"><p>Your cart is empty.</p></div>';
    startIslands(document);
  };
  const legend = () =>
    [...document.querySelectorAll('legend')]
      .find((l) => l.textContent === 'How you’ll pay')
      ?.closest('fieldset') as HTMLFieldSetElement;
  const fill = () => {
    const byLabel = (text: string) =>
      document.getElementById(
        [...document.querySelectorAll('label')].find((l) => l.textContent === text)!.htmlFor,
      ) as HTMLInputElement;
    byLabel('Your name').value = 'Sipho';
    byLabel('Phone').value = '082 555 0101';
    byLabel('I agree to be contacted about my order.').checked = true;
    document.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));
  };

  it('offers card or EFT when both are on, and sends a card order to the provider', async () => {
    const fetchMock = vi.fn<(url: string, init?: RequestInit) => Promise<Response>>((url) =>
      url === '/cart/price'
        ? respond(200, priced(true, true))
        : respond(201, {
            placed: true,
            pay_url: 'https://c.yoco.com/checkout/ch_9',
            order_url: '/order/x',
          }),
    );
    vi.stubGlobal('fetch', fetchMock);
    mount();
    await settle();
    expect(legend().hidden).toBe(false);
    fill();
    await settle();
    expect(JSON.parse(String(fetchMock.mock.calls.at(-1)![1]!.body))).toMatchObject({
      payment: 'card',
      fulfilment: 'collection',
    });
    expect(document.querySelector('[role="status"]')!.textContent).toBe(
      'Order placed. Taking you to pay…',
    );
    expect(localStorage.getItem('wurk-cart')).toBeNull();
    vi.unstubAllGlobals();
  });

  it('hides the choice when only EFT is on, and orders by EFT', async () => {
    const fetchMock = vi.fn<(url: string, init?: RequestInit) => Promise<Response>>((url) =>
      url === '/cart/price'
        ? respond(200, priced(false, true))
        : respond(201, { placed: true, order_url: '/order/x' }),
    );
    vi.stubGlobal('fetch', fetchMock);
    mount();
    await settle();
    expect(legend().hidden).toBe(true);
    fill();
    await settle();
    expect(JSON.parse(String(fetchMock.mock.calls.at(-1)![1]!.body))).toMatchObject({
      payment: 'offline',
    });
    vi.unstubAllGlobals();
  });
});
