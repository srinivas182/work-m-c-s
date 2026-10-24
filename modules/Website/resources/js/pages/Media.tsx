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

interface Props {
  photos: Photo[];
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
              AI image
            </Badge>
          )}
        </div>
        <p className="text-fg-muted text-sm">
          {photo.width}×{photo.height} · {photo.kb} KB
        </p>
        <p className="text-sm">
          {photo.usedIn.length > 0 ? (
            <>Used on: {photo.usedIn.join(', ')}</>
          ) : (
            <span className="text-fg-muted">Not used anywhere</span>
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
            label="Description"
            hint="Describes the photo for people who can’t see it. Offered wherever you use it."
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
              Save description
            </Button>
            <Button
              type="button"
              size="sm"
              variant="ghost"
              disabled={photo.usedIn.length > 0}
              aria-label={`Delete ${photo.name}`}
              onClick={() => router.delete(`${base}/${photo.id}`, { preserveScroll: true })}
            >
              <Trash2 /> Delete
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

export default function Media({ photos, usedMb, limitMb, base }: Props) {
  return (
    <AppShell title="Media">
      <Head title="Media" />
      <div className="grid gap-6">
        <PageHeader
          title="Media"
          description={limitMb > 0 ? `${usedMb} MB of ${limitMb} MB used.` : `${usedMb} MB used.`}
        />
        {photos.length === 0 ? (
          <EmptyState
            icon={ImageIcon}
            title="No photos yet"
            description="Photos you upload in the editor appear here."
          />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {photos.map((p) => (
              <PhotoCard key={p.id} photo={p} base={base} />
            ))}
          </div>
        )}
      </div>
    </AppShell>
  );
}
