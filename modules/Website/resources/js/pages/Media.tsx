import { Head, router, useForm } from '@inertiajs/react';
import { ImageIcon, Trash2 } from 'lucide-react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  EmptyState,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Photo {
  id: string;
  src: string;
  name: string;
  description: string | null;
  ai: boolean;
  kb: number;
  width: number;
  height: number;
  usedIn: string[];
}

interface Video {
  id: string;
  src: string;
  poster: string;
  name: string;
  seconds: number;
  kb: number;
  usedIn: string[];
}

interface Props {
  photos: Photo[];
  /** Short videos for the video hero and the Video section (Sprint 20.2). */
  videos?: Video[];
  usedMb: number;
  limitMb: number;
  base: string;
}

function PhotoCard({ photo, base }: { photo: Photo; base: string }) {
  const form = useForm({ description: photo.description ?? '' });
  return (
    <Card>
      <CardContent className="grid gap-3 p-4">
        <div className="relative">
          <img
            src={photo.src}
            alt={photo.description ?? ''}
            className="aspect-[4/3] w-full rounded-lg object-cover"
            loading="lazy"
          />
          {photo.ai && (
            <Badge tone="info" className="absolute top-2 left-2">
              {__('AI image')}
            </Badge>
          )}
        </div>
        <p className="text-fg-muted text-sm">
          {photo.width}×{photo.height} · {photo.kb} {__('KB')}
        </p>
        <p className="text-sm">
          {photo.usedIn.length > 0 ? (
            <>
              {__('Used on:')} {photo.usedIn.join(', ')}
            </>
          ) : (
            <span className="text-fg-muted">{__('Not used anywhere')}</span>
          )}
        </p>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            form.put(`${base}/${photo.id}`, { preserveScroll: true });
          }}
          className="grid gap-2"
        >
          <Field
            label={__('Description')}
            hint={__(
              'Describes the photo for people who can’t see it. Offered wherever you use it.',
            )}
            error={form.errors.description}
          >
            <Input
              value={form.data.description}
              maxLength={200}
              onChange={(e) => form.setData('description', e.target.value)}
            />
          </Field>
          <div className="flex gap-2">
            <Button
              type="submit"
              size="sm"
              variant="secondary"
              loading={form.processing}
              disabled={!form.isDirty}
            >
              {__('Save description')}
            </Button>
            <Button
              type="button"
              size="sm"
              variant="ghost"
              disabled={photo.usedIn.length > 0}
              aria-label={`Delete ${photo.name}`}
              onClick={() => router.delete(`${base}/${photo.id}`, { preserveScroll: true })}
            >
              <Trash2 /> {__('Delete')}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

function VideoCard({ video, base }: { video: Video; base: string }) {
  return (
    <Card>
      <CardContent className="grid gap-3 p-4">
        <video
          src={video.src}
          poster={video.poster}
          controls
          preload="none"
          aria-label={video.name}
          className="aspect-video w-full rounded-lg bg-black object-contain"
        />
        <p className="text-fg-muted text-sm">
          {video.name} · {video.seconds} {__('s ·')} {Math.round(video.kb / 102.4) / 10} {__('MB')}
        </p>
        <p className="text-sm">
          {video.usedIn.length > 0 ? (
            <>
              {__('Used on:')} {video.usedIn.join(', ')}
            </>
          ) : (
            <span className="text-fg-muted">{__('Not used anywhere')}</span>
          )}
        </p>
        <div>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            disabled={video.usedIn.length > 0}
            aria-label={`Delete ${video.name}`}
            onClick={() => router.delete(`${base}/${video.id}`, { preserveScroll: true })}
          >
            <Trash2 /> {__('Delete')}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

export default function Media({ photos, videos = [], usedMb, limitMb, base }: Props) {
  return (
    <AppShell title={__('Media')}>
      <Head title={__('Media')} />
      <div className="grid gap-6">
        <PageHeader
          title={__('Media')}
          description={limitMb > 0 ? `${usedMb} MB of ${limitMb} MB used.` : `${usedMb} MB used.`}
        />
        {photos.length === 0 ? (
          <EmptyState
            icon={ImageIcon}
            title={__('No photos yet')}
            description={__('Photos you upload in the editor appear here.')}
          />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {photos.map((p) => (
              <PhotoCard key={p.id} photo={p} base={base} />
            ))}
          </div>
        )}
        {videos.length > 0 && (
          <section aria-labelledby="media-videos" className="grid gap-4">
            <h2 id="media-videos" className="text-lg font-semibold">
              {__('Videos')}
            </h2>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {videos.map((v) => (
                <VideoCard key={v.id} video={v} base={base} />
              ))}
            </div>
          </section>
        )}
      </div>
    </AppShell>
  );
}
