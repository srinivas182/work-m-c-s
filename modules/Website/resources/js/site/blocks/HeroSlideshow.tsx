import { photoSrcSet } from './shared';
import { ChevronLeft, ChevronRight, Pause, Play } from 'lucide-react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';

const REDUCED = '(prefers-reduced-motion: reduce)';

/** The visitor's reduced-motion preference; on the server we assume reduced (never auto-advance). */
function useReducedMotion(): boolean {
  return useSyncExternalStore(
    (notify) => {
      const mq = window.matchMedia(REDUCED);
      mq.addEventListener('change', notify);
      return () => mq.removeEventListener('change', notify);
    },
    () => window.matchMedia(REDUCED).matches,
    () => true,
  );
}

export interface Photo {
  src: string;
  alt: string;
}

/**
 * Guarded slideshow (WCAG 2.2): swipe or tap through; never advances on its own
 * unless the owner switched that on — and then slowly, with a visible pause
 * button, pausing on hover/focus, and never for visitors who asked for reduced
 * motion. Only the first photo loads immediately.
 */
export function HeroSlideshow({
  photos,
  autoplay,
  label,
}: {
  photos: Photo[];
  autoplay: boolean;
  label: string;
}) {
  const track = useRef<HTMLUListElement>(null);
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const [held, setHeld] = useState(false);
  const reduced = useReducedMotion();
  const playing = autoplay && !reduced && !paused && photos.length > 1;

  const go = (to: number) => {
    const next = (to + photos.length) % photos.length;
    const el = track.current?.children[next] as HTMLElement | undefined;
    track.current?.scrollTo({ left: el?.offsetLeft ?? 0, behavior: 'smooth' });
    setIndex(next);
  };

  useEffect(() => {
    if (!playing || held) return;
    const t = setInterval(() => go(index + 1), 7000);
    return () => clearInterval(t);
  });

  return (
    <section
      aria-roledescription="carousel"
      aria-label={label}
      className="relative"
      onMouseEnter={() => setHeld(true)}
      onMouseLeave={() => setHeld(false)}
      onFocus={() => setHeld(true)}
      onBlur={() => setHeld(false)}
    >
      <ul
        ref={track}
        className="flex snap-x snap-mandatory [scrollbar-width:none] overflow-x-auto [&::-webkit-scrollbar]:hidden"
        onScroll={(e) => {
          const el = e.currentTarget;
          setIndex(Math.round(el.scrollLeft / Math.max(1, el.clientWidth)));
        }}
      >
        {photos.map((p, i) => (
          <li
            key={i}
            aria-roledescription="slide"
            aria-label={`${i + 1} of ${photos.length}`}
            className="w-full shrink-0 snap-start"
          >
            <img
              src={p.src}
              srcSet={photoSrcSet(p.src)}
              sizes="(min-width: 768px) 45vw, 100vw"
              alt={p.alt}
              loading={i === 0 ? 'eager' : 'lazy'}
              fetchPriority={i === 0 ? 'high' : 'low'}
              className="aspect-[4/5] w-full rounded-[var(--s-radius)] object-cover sm:aspect-[5/6]"
            />
          </li>
        ))}
      </ul>
      {photos.length > 1 && (
        <div className="mt-4 flex items-center justify-between gap-3">
          <div className="flex gap-1.5" role="group" aria-label="Choose a photo">
            {photos.map((_, i) => (
              <button
                key={i}
                type="button"
                aria-label={`Show photo ${i + 1}`}
                aria-current={i === index}
                onClick={() => go(i)}
                className="grid size-6 place-items-center"
              >
                <span
                  className={`h-1.5 rounded-full transition-all ${i === index ? 'w-5 bg-[var(--s-brand)]' : 'w-1.5 bg-[var(--s-border)]'}`}
                />
              </button>
            ))}
          </div>
          <div className="flex gap-1">
            {autoplay && !reduced && (
              <button
                type="button"
                onClick={() => setPaused((v) => !v)}
                aria-label={playing ? 'Pause slideshow' : 'Play slideshow'}
                className="grid size-11 place-items-center rounded-full border border-[var(--s-border)]"
              >
                {playing ? (
                  <Pause className="size-4" aria-hidden />
                ) : (
                  <Play className="size-4" aria-hidden />
                )}
              </button>
            )}
            <button
              type="button"
              onClick={() => go(index - 1)}
              aria-label="Previous photo"
              className="grid size-11 place-items-center rounded-full border border-[var(--s-border)]"
            >
              <ChevronLeft className="size-5" aria-hidden />
            </button>
            <button
              type="button"
              onClick={() => go(index + 1)}
              aria-label="Next photo"
              className="grid size-11 place-items-center rounded-full border border-[var(--s-border)]"
            >
              <ChevronRight className="size-5" aria-hidden />
            </button>
          </div>
        </div>
      )}
    </section>
  );
}
