import { Clapperboard, Play } from 'lucide-react';
import type { BlockDefinition, VideoValue } from '../types';
import { Heading, Section, photoSrcSet } from './shared';

export interface VideoProps extends Record<string, unknown> {
  heading: string;
  text: string;
  video: VideoValue;
  /** What the video shows, for people who can't see it (and as the play button's name). */
  description: string;
}

/**
 * Video (Sprint 20.2): a short clip the visitor chooses to play, with sound and controls. Until they tap, only the
 * still poster loads — no video data is spent on a visitor who doesn't want it (site-islands.ts).
 */
export const Video: BlockDefinition<VideoProps> = {
  type: 'Video',
  version: 1,
  label: 'Video',
  description: 'A short video of your work or premises. It plays only when a visitor taps it.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    text: { kind: 'text', inline: true, label: 'Text', multiline: true, max: 240 },
    video: { kind: 'video', label: 'Video' },
    description: {
      kind: 'text',
      label: 'What the video shows (for screen readers)',
      max: 140,
    },
  },
  isEmpty: (p) => !p.video?.src,
  defaults: {
    heading: 'See us at work',
    text: '',
    video: { src: '', poster: '' },
    description: '',
  },
  render: (p, site) => {
    const name = p.description.trim() || `Video from ${site.businessName}`;
    return (
      <Section>
        {(p.heading || p.text) && (
          <div className="mb-10 max-w-3xl">
            <Heading intro={p.text || undefined}>{p.heading}</Heading>
          </div>
        )}
        {p.video?.src ? (
          <figure
            data-island="video-play"
            data-src={site.editing ? undefined : p.video.src}
            data-name={name}
            className="relative aspect-video overflow-hidden rounded-[var(--s-radius)] bg-black"
          >
            {/* Without JavaScript the poster links to the video itself, so it still plays. */}
            <a
              href={site.editing ? undefined : p.video.src}
              data-video-play
              aria-label={`Play: ${name}`}
              className="group block size-full"
            >
              <img
                src={p.video.poster}
                srcSet={photoSrcSet(p.video.poster)}
                sizes="(min-width: 1152px) 1152px, 100vw"
                alt=""
                loading="lazy"
                className="size-full object-cover"
              />
              <span
                aria-hidden
                className="absolute inset-0 grid place-items-center bg-black/15 transition-colors group-hover:bg-black/30"
              >
                <span className="grid size-20 place-items-center rounded-full bg-[var(--s-brand)] text-[var(--s-on-brand)] shadow-lg transition-transform group-hover:scale-105">
                  <Play className="ml-1 size-9" fill="currentColor" />
                </span>
              </span>
            </a>
          </figure>
        ) : (
          site.editing && (
            <div className="grid aspect-video place-items-center rounded-[var(--s-radius)] border-2 border-dashed border-[var(--s-border)] bg-[var(--s-brand-softer)] text-[var(--s-brand-text)]">
              <span className="flex flex-col items-center gap-2 px-6 text-center text-sm font-medium">
                <Clapperboard className="size-7" aria-hidden />
                Add a short video (up to 30 seconds)
              </span>
            </div>
          )
        )}
      </Section>
    );
  },
};
