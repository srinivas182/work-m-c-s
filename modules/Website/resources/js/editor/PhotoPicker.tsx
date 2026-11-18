import { ArrowDown, ArrowUp, ImagePlus, Loader2, Trash2 } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { AiConfirm } from '@/components/AiConfirm';
import { firstError, request } from './http';
import { __ } from '@/i18n';

export interface PhotoValue {
  src: string;
  alt: string;
  srcset?: string;
  width?: number;
  height?: number;
  asset?: string;
}

/** A photo from the design (a stand-in or HQ's demo photo), not the business's own — mirrors TemplatePhotos::DEMO. */
export function isDemoPhoto(src: string): boolean {
  return /\/(?:images\/)?templates\/[a-z0-9-]+\/[a-z0-9-]+-\d+\.webp/i.test(src);
}

interface Library {
  photos: (PhotoValue & { name: string; ai?: boolean })[];
  /** The design's demo photos (20.3): one per place, to swap a demo for one that fits better. */
  demo?: (PhotoValue & { name: string })[];
  used_mb: number;
  limit_mb: number;
}

/**
 * Photo field for the editor: upload from the device or choose an uploaded
 * photo, then describe it for people who can't see it. Used for one photo
 * (max 1) or a list (galleries, hero collage/slideshow).
 */
export function PhotoPicker({
  value,
  onChange,
  max,
  photosUrl,
  withAlt = true,
  allowAi = false,
  ai,
}: {
  value: PhotoValue[];
  onChange: (next: PhotoValue[]) => void;
  max: number;
  photosUrl: string;
  /** False when the block keeps the description in its own field. */
  withAlt?: boolean;
  /** AI images are decorative: only offered (and shown) in sections that allow them. */
  allowAi?: boolean;
  ai?: { url: string; quoteUrl: string; tenantBase: string };
}) {
  const [prompt, setPrompt] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [generating, setGenerating] = useState(false);
  const generate = async () => {
    if (!ai) return;
    setGenerating(true);
    setError(null);
    const { status, data } = await request<PhotoValue>('POST', ai.url, {
      prompt,
      confirmation: crypto.randomUUID(),
    });
    setGenerating(false);
    setConfirming(false);
    if (status === 201) {
      onChange(max === 1 ? [{ ...data }] : [...value, data]);
      setPrompt('');
      setLibrary(null);
    } else {
      setError(
        firstError(data) ??
          (data as { message?: string })?.message ??
          'The image couldn’t be made.',
      );
    }
  };
  const id = useId();
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [library, setLibrary] = useState<Library | null>(null);
  const full = value.length >= max;

  const upload = async (file: File) => {
    setBusy(true);
    setError(null);
    const form = new FormData();
    form.append('photo', file);
    const { status, data } = await request<PhotoValue>('POST', photosUrl, form);
    setBusy(false);
    if (status === 201) {
      onChange(max === 1 ? [{ ...data, alt: value[0]?.alt ?? '' }] : [...value, data]);
      setLibrary(null);
    } else {
      setError(firstError(data) ?? 'That photo couldn’t be uploaded.');
    }
  };

  const openLibrary = async () => {
    const { data } = await request<Library>('GET', photosUrl);
    setLibrary(data);
  };

  const update = (i: number, patch: Partial<PhotoValue>) =>
    onChange(value.map((p, j) => (j === i ? { ...p, ...patch } : p)));
  const move = (i: number, d: number) => {
    const next = [...value];
    const [item] = next.splice(i, 1);
    if (item) next.splice(i + d, 0, item);
    onChange(next);
  };

  return (
    <div className="grid gap-3 text-sm">
      {value.map((photo, i) => (
        <div
          key={`${photo.src}-${i}`}
          className="grid grid-cols-[72px_1fr] gap-3 rounded-lg border border-[#d9d6e8] p-2"
        >
          <div className="relative">
            <img src={photo.src} alt="" className="size-[72px] rounded-md object-cover" />
            {isDemoPhoto(photo.src) && (
              <span className="absolute inset-x-0 bottom-0 rounded-b-md bg-[#7a4b00] px-1 py-0.5 text-center text-[10px] font-semibold text-white">
                {__('Demo')}
              </span>
            )}
          </div>
          <div className="grid gap-1.5">
            {isDemoPhoto(photo.src) && (
              <p className="text-[#7a4b00]">
                {__('Demo photo — replace it with one of your own business.')}
              </p>
            )}
            {withAlt && (
              <label className="grid gap-1">
                <span className="font-medium">{__('Describe this photo')}</span>
                <input
                  className="rounded-md border border-[#d9d6e8] px-2 py-1.5"
                  value={photo.alt}
                  maxLength={140}
                  placeholder={__('e.g. Stylist braiding a client’s hair')}
                  onChange={(e) => update(i, { alt: e.target.value })}
                />
              </label>
            )}
            {withAlt && !photo.alt && (
              <span className="text-[#8a1c1c]">
                {__('Add a short description for people using screen readers.')}
              </span>
            )}
            <div className="flex gap-1">
              {max > 1 && (
                <>
                  <button
                    type="button"
                    className="rounded p-1 hover:bg-[#f2f0f9] disabled:opacity-40"
                    disabled={i === 0}
                    onClick={() => move(i, -1)}
                    aria-label={__('Move photo earlier')}
                  >
                    <ArrowUp className="size-4" aria-hidden />
                  </button>
                  <button
                    type="button"
                    className="rounded p-1 hover:bg-[#f2f0f9] disabled:opacity-40"
                    disabled={i === value.length - 1}
                    onClick={() => move(i, 1)}
                    aria-label={__('Move photo later')}
                  >
                    <ArrowDown className="size-4" aria-hidden />
                  </button>
                </>
              )}
              <button
                type="button"
                className="rounded p-1 text-[#8a1c1c] hover:bg-[#fdecec]"
                onClick={() => onChange(value.filter((_, j) => j !== i))}
                aria-label={__('Remove photo')}
              >
                <Trash2 className="size-4" aria-hidden />
              </button>
            </div>
          </div>
        </div>
      ))}

      {(!full || max === 1) && (
        <div className="flex flex-wrap gap-2">
          <input
            ref={input}
            id={id}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="sr-only"
            // Uploads start from the visible button; the input itself is named and kept out of the tab order.
            aria-label={__('Choose a photo to upload')}
            tabIndex={-1}
            onChange={(e) => {
              const file = e.target.files?.[0];
              if (file) void upload(file);
              e.target.value = '';
            }}
          />
          <button
            type="button"
            disabled={busy}
            onClick={() => input.current?.click()}
            className="inline-flex items-center gap-1.5 rounded-md bg-[#5b3df5] px-3 py-2 font-semibold text-white disabled:opacity-60"
          >
            {busy ? (
              <Loader2 className="size-4 animate-spin" aria-hidden />
            ) : (
              <ImagePlus className="size-4" aria-hidden />
            )}
            {busy ? 'Uploading…' : max === 1 && value.length ? 'Replace photo' : 'Upload photo'}
          </button>
          <button
            type="button"
            onClick={() => void openLibrary()}
            className="rounded-md border border-[#d9d6e8] px-3 py-2 font-medium"
          >
            {__('Choose from my photos')}
          </button>
        </div>
      )}
      {full && max > 1 && (
        <p className="text-[#5b5776]">
          {__('Up to')} {max} {__('photos.')}
        </p>
      )}
      {error && (
        <p role="alert" className="text-[#8a1c1c]">
          {error}
        </p>
      )}

      {allowAi && ai && !full && (
        <div className="grid gap-2 rounded-lg border border-dashed border-[#d9d6e8] p-2">
          <label className="grid gap-1" htmlFor={`${id}-prompt`}>
            <span className="font-medium">{__('Or generate an image with WURK AI')}</span>
            <span className="text-[#5b5776]">
              {__('For decoration only — use real photos to show your work, team or premises.')}
            </span>
            <input
              id={`${id}-prompt`}
              value={prompt}
              maxLength={300}
              onChange={(e) => setPrompt(e.target.value)}
              placeholder={__('e.g. warm morning light in a calm, modern salon')}
              className="rounded-md border border-[#d9d6e8] px-2 py-1.5"
            />
          </label>
          <button
            type="button"
            disabled={prompt.trim().length < 3 || generating}
            onClick={() => setConfirming(true)}
            className="justify-self-start rounded-md bg-[#5b3df5] px-3 py-1.5 font-semibold text-white disabled:opacity-60"
          >
            {generating ? 'Generating…' : 'Generate an image'}
          </button>
          <AiConfirm
            open={confirming}
            onOpenChange={setConfirming}
            quoteUrl={`${ai.quoteUrl}?action=image`}
            tenantBase={ai.tenantBase}
            busy={generating}
            onConfirm={generate}
          />
        </div>
      )}

      {library && (
        <div className="grid gap-2 rounded-lg border border-[#d9d6e8] p-2">
          {library.limit_mb > 0 && (
            <p className="text-[#5b5776]">
              {library.used_mb} {__('MB of')} {library.limit_mb} {__('MB used')}
            </p>
          )}
          {(library.demo?.length ?? 0) > 0 && (
            <details className="text-[#5b5776]">
              <summary className="cursor-pointer font-medium">
                {__('Or another demo photo from your design')}
              </summary>
              <div className="mt-2 grid grid-cols-3 gap-2">
                {(library.demo ?? []).map((p) => (
                  <button
                    key={p.src}
                    type="button"
                    onClick={() => {
                      const chosen: PhotoValue = { src: p.src, alt: value[0]?.alt ?? '' };
                      onChange(max === 1 ? [chosen] : [...value, chosen].slice(0, max));
                      setLibrary(null);
                    }}
                    className="overflow-hidden rounded-md ring-[#5b3df5] hover:ring-2"
                    aria-label={`Use the demo photo: ${p.name}`}
                  >
                    <img src={p.src} alt="" className="aspect-square w-full object-cover" />
                  </button>
                ))}
              </div>
            </details>
          )}
          {library.photos.filter((p) => allowAi || !p.ai).length === 0 ? (
            <p>{__('No photos yet — upload one.')}</p>
          ) : (
            <div className="grid grid-cols-3 gap-2">
              {library.photos
                .filter((p) => allowAi || !p.ai)
                .map((p) => (
                  <button
                    key={p.src}
                    type="button"
                    onClick={() => {
                      const chosen: PhotoValue = {
                        src: p.src,
                        alt: p.alt ?? '',
                        srcset: p.srcset,
                        width: p.width,
                        height: p.height,
                        asset: p.asset,
                      };
                      onChange(max === 1 ? [chosen] : [...value, chosen].slice(0, max));
                      setLibrary(null);
                    }}
                    className="relative overflow-hidden rounded-md ring-[#5b3df5] hover:ring-2"
                    aria-label={`Use ${p.name}${p.ai ? ' (AI image)' : ''}`}
                  >
                    <img src={p.src} alt="" className="aspect-square w-full object-cover" />
                    {p.ai && (
                      <span
                        aria-hidden
                        className="absolute top-1 left-1 rounded bg-black/70 px-1.5 py-0.5 text-[11px] font-semibold text-white"
                      >
                        {__('AI')}
                      </span>
                    )}
                  </button>
                ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
