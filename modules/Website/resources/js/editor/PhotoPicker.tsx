import { ArrowDown, ArrowUp, ImagePlus, Loader2, Trash2 } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { firstError, request } from './http';

export interface PhotoValue {
  src: string;
  alt: string;
  srcset?: string;
  width?: number;
  height?: number;
  asset?: string;
}

interface Library {
  photos: (PhotoValue & { name: string })[];
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
}: {
  value: PhotoValue[];
  onChange: (next: PhotoValue[]) => void;
  max: number;
  photosUrl: string;
  /** False when the block keeps the description in its own field. */
  withAlt?: boolean;
}) {
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
          <img src={photo.src} alt="" className="size-[72px] rounded-md object-cover" />
          <div className="grid gap-1.5">
            {withAlt && (
              <label className="grid gap-1">
                <span className="font-medium">Describe this photo</span>
                <input
                  className="rounded-md border border-[#d9d6e8] px-2 py-1.5"
                  value={photo.alt}
                  maxLength={140}
                  placeholder="e.g. Stylist braiding a client’s hair"
                  onChange={(e) => update(i, { alt: e.target.value })}
                />
              </label>
            )}
            {withAlt && !photo.alt && (
              <span className="text-[#8a1c1c]">
                Add a short description for people using screen readers.
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
                    aria-label="Move photo earlier"
                  >
                    <ArrowUp className="size-4" aria-hidden />
                  </button>
                  <button
                    type="button"
                    className="rounded p-1 hover:bg-[#f2f0f9] disabled:opacity-40"
                    disabled={i === value.length - 1}
                    onClick={() => move(i, 1)}
                    aria-label="Move photo later"
                  >
                    <ArrowDown className="size-4" aria-hidden />
                  </button>
                </>
              )}
              <button
                type="button"
                className="rounded p-1 text-[#8a1c1c] hover:bg-[#fdecec]"
                onClick={() => onChange(value.filter((_, j) => j !== i))}
                aria-label="Remove photo"
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
            Choose from my photos
          </button>
        </div>
      )}
      {full && max > 1 && <p className="text-[#5b5776]">Up to {max} photos.</p>}
      {error && (
        <p role="alert" className="text-[#8a1c1c]">
          {error}
        </p>
      )}

      {library && (
        <div className="grid gap-2 rounded-lg border border-[#d9d6e8] p-2">
          <p className="text-[#5b5776]">
            {library.used_mb} MB of {library.limit_mb} MB used
          </p>
          {library.photos.length === 0 ? (
            <p>No photos yet — upload one.</p>
          ) : (
            <div className="grid grid-cols-3 gap-2">
              {library.photos.map((p) => (
                <button
                  key={p.src}
                  type="button"
                  onClick={() => {
                    const chosen: PhotoValue = {
                      src: p.src,
                      alt: '',
                      srcset: p.srcset,
                      width: p.width,
                      height: p.height,
                      asset: p.asset,
                    };
                    onChange(max === 1 ? [chosen] : [...value, chosen].slice(0, max));
                    setLibrary(null);
                  }}
                  className="overflow-hidden rounded-md ring-[#5b3df5] hover:ring-2"
                  aria-label={`Use ${p.name}`}
                >
                  <img src={p.src} alt="" className="aspect-square w-full object-cover" />
                </button>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
