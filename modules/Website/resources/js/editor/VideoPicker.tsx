import { Clapperboard, Loader2, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import type { VideoValue } from '../site/types';
import { firstError, request } from './http';
import { __ } from '@/i18n';

interface LibraryVideo extends VideoValue {
  id: string;
  name: string;
  seconds: number;
}

interface Library {
  videos: LibraryVideo[];
  max_mb: number;
  max_seconds: number;
}

/**
 * Video field for the editor (Sprint 20.2): upload a short clip from the device, or choose one already uploaded.
 * The server re-encodes it (light H.264, at most 30 seconds) and makes the still poster that visitors see
 * under "reduce motion", Save-Data and on slow connections.
 */
export function VideoPicker({
  value,
  onChange,
  videosUrl,
}: {
  value: VideoValue;
  onChange: (next: VideoValue) => void;
  /** Absent in the staff template editor: owners add their own videos. */
  videosUrl?: string;
}) {
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [library, setLibrary] = useState<Library | null>(null);

  if (!videosUrl) {
    return (
      <p className="text-sm text-[#5b5776]">
        {__('Owners add their own video. Until then, the first photo is shown.')}
      </p>
    );
  }

  const upload = async (file: File) => {
    setBusy(true);
    setError(null);
    const form = new FormData();
    form.append('video', file);
    const { status, data } = await request<LibraryVideo>('POST', videosUrl, form);
    setBusy(false);
    if (status === 201) {
      onChange({ src: data.src, poster: data.poster });
      setLibrary(null);
    } else {
      setError(firstError(data) ?? 'That video couldn’t be uploaded.');
    }
  };

  const openLibrary = async () => {
    const { data } = await request<Library>('GET', videosUrl);
    setLibrary(data);
  };

  return (
    <div className="grid gap-3 text-sm">
      {value.src && (
        <div className="grid grid-cols-[96px_1fr] items-center gap-3 rounded-lg border border-[#d9d6e8] p-2">
          <img src={value.poster} alt="" className="aspect-video w-24 rounded-md object-cover" />
          <div className="flex items-center justify-between gap-2">
            <span className="text-[#5b5776]">
              {__('Plays muted, on a loop, with a pause button.')}
            </span>
            <button
              type="button"
              className="rounded p-1 text-[#8a1c1c] hover:bg-[#fdecec]"
              onClick={() => onChange({ src: '', poster: '' })}
              aria-label={__('Remove video')}
            >
              <Trash2 className="size-4" aria-hidden />
            </button>
          </div>
        </div>
      )}
      <div className="flex flex-wrap gap-2">
        <input
          ref={input}
          type="file"
          accept="video/mp4,video/quicktime,video/webm"
          className="sr-only"
          aria-label={__('Choose a video to upload')}
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
            <Clapperboard className="size-4" aria-hidden />
          )}
          {busy ? 'Preparing your video…' : value.src ? 'Replace video' : 'Upload video'}
        </button>
        <button
          type="button"
          onClick={() => void openLibrary()}
          className="rounded-md border border-[#d9d6e8] px-3 py-2 font-medium"
        >
          {__('Choose from my videos')}
        </button>
      </div>
      <p className="text-[#5b5776]">
        {busy
          ? 'This can take up to a minute. You can keep editing.'
          : 'MP4, MOV or WebM, up to 20 MB. Only the first 30 seconds are kept, without sound on the page.'}
      </p>
      {error && (
        <p role="alert" className="text-[#8a1c1c]">
          {error}
        </p>
      )}
      {library && (
        <div className="grid gap-2 rounded-lg border border-[#d9d6e8] p-2">
          {library.videos.length === 0 ? (
            <p>{__('No videos yet — upload one.')}</p>
          ) : (
            <div className="grid grid-cols-2 gap-2">
              {library.videos.map((v) => (
                <button
                  key={v.id}
                  type="button"
                  onClick={() => {
                    onChange({ src: v.src, poster: v.poster });
                    setLibrary(null);
                  }}
                  className="relative overflow-hidden rounded-md ring-[#5b3df5] hover:ring-2"
                  aria-label={`Use ${v.name} (${v.seconds} seconds)`}
                >
                  <img src={v.poster} alt="" className="aspect-video w-full object-cover" />
                  <span
                    aria-hidden
                    className="absolute right-1 bottom-1 rounded bg-black/70 px-1.5 py-0.5 text-[11px] font-semibold text-white"
                  >
                    0:{String(v.seconds).padStart(2, '0')}
                  </span>
                </button>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
