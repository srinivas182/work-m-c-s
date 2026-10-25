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

const ISLANDS: Record<string, (root: HTMLElement) => void> = {
  'before-after': beforeAfter,
  slideshow,
  promotion,
};

export function startIslands(scope: ParentNode = document): void {
  scope
    .querySelectorAll<HTMLElement>('[data-island]')
    .forEach((root) => ISLANDS[root.dataset.island ?? '']?.(root));
}

if (typeof document !== 'undefined' && !(window as { __noAutoIslands?: boolean }).__noAutoIslands) {
  startIslands();
}
