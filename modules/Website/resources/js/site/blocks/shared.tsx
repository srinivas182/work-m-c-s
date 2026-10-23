import { ImagePlus } from 'lucide-react';
import type { ReactNode } from 'react';

/** Section shell: consistent width and rhythm for every block. */
export function Section({
  id,
  tone = 'plain',
  children,
  narrow = false,
}: {
  id?: string;
  tone?: 'plain' | 'surface' | 'brand';
  children: ReactNode;
  narrow?: boolean;
}) {
  const bg =
    tone === 'surface'
      ? 'bg-[var(--s-surface)]'
      : tone === 'brand'
        ? 'bg-[var(--s-brand)] text-[var(--s-on-brand)]'
        : '';
  return (
    <section id={id} className={`px-5 py-16 sm:px-8 sm:py-24 ${bg}`}>
      <div className="mx-auto max-w-6xl">
        {narrow ? <div className="max-w-3xl">{children}</div> : children}
      </div>
    </section>
  );
}

export function Heading({ children, intro }: { children: ReactNode; intro?: string }) {
  return (
    <>
      <h2 className="font-[family-name:var(--s-font-display)] text-3xl font-bold tracking-[-0.01em] text-balance sm:text-4xl">
        {children}
      </h2>
      {intro && <p className="mt-4 max-w-2xl text-lg text-[var(--s-fg-muted)]">{intro}</p>}
    </>
  );
}

/** Editor-only prompt where a photo will go; nothing is shown on a live site. */
export function PhotoPrompt({
  editing,
  className = '',
  text = 'Add a photo',
}: {
  editing: boolean;
  className?: string;
  text?: string;
}) {
  if (!editing) return null;
  return (
    <div
      className={`grid place-items-center rounded-[var(--s-radius)] border-2 border-dashed border-[var(--s-border)] bg-[var(--s-brand-softer)] text-[var(--s-brand-text)] ${className}`}
    >
      <span className="flex flex-col items-center gap-2 px-4 text-center text-sm font-medium">
        <ImagePlus className="size-6" aria-hidden />
        {text}
      </span>
    </div>
  );
}

export function EmptyHint({ editing, children }: { editing: boolean; children: ReactNode }) {
  return editing ? (
    <p className="rounded-[var(--s-radius)] border border-dashed border-[var(--s-border)] p-6 text-center text-[var(--s-fg-muted)]">
      {children}
    </p>
  ) : null;
}

export const paragraphs = (text: string) =>
  text
    .split(/\n\s*\n/)
    .map((t) => t.trim())
    .filter(Boolean);

/**
 * Uploaded photos are stored as "<id>-<width>.webp", with a 640px version whenever
 * the largest is wider than that (Sprint 4.3). Derive srcset from the URL so phones
 * download the small file; other URLs are left as they are.
 */
export function photoSrcSet(src: string): string | undefined {
  const match = src.match(/^(.*)-(\d+)\.webp$/);
  const width = match ? Number(match[2]) : 0;
  if (!match || width <= 640) return undefined;
  return `${match[1]}-640.webp 640w, ${src} ${width}w`;
}
