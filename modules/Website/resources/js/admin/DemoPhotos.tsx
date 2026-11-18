import { router, useForm } from '@inertiajs/react';
import { ExternalLink, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/design-system/components/card';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/design-system/components/select';
import { __ } from '@/i18n';

export interface DemoPhotoSlot {
  slot: string;
  shows: string;
  search: string;
  decorative: boolean;
  /** HQ's photo (smallest size), or the stand-in. */
  photo: string;
  chosen: {
    photographer: string | null;
    licence: string | null;
    source: string | null;
    ai: boolean;
    width: number;
    height: number;
  } | null;
  find: { site: string; url: string }[];
}

function SlotRow({
  slot,
  base,
  licences,
}: {
  slot: DemoPhotoSlot;
  base: string;
  licences: string[];
}) {
  const [open, setOpen] = useState(false);
  const form = useForm<{
    link: string;
    photo: File | null;
    photographer: string;
    licence: string;
  }>({ link: '', photo: null, photographer: '', licence: '' });
  const allowed = licences.filter((l) => slot.decorative || l !== 'WURK AI');
  const url = `${base}/demo-photos/${slot.slot}`;

  return (
    <li className="grid gap-3 py-4 sm:grid-cols-[160px_1fr]">
      <div className="relative">
        <img
          src={slot.photo}
          alt=""
          className="aspect-[4/3] w-full rounded-lg object-cover"
          loading="lazy"
        />
        {!slot.chosen && (
          <Badge tone="warning" className="absolute top-2 left-2">
            {__('Stand-in')}
          </Badge>
        )}
        {slot.chosen?.ai && (
          <Badge tone="info" className="absolute top-2 left-2">
            {__('AI image')}
          </Badge>
        )}
      </div>
      <div className="grid content-start gap-2 text-sm">
        <p>
          <span className="font-semibold capitalize">{slot.slot.replace(/-/g, ' ')}</span>
          {slot.decorative && <span className="text-fg-muted"> {__('· decorative')}</span>}
        </p>
        <p className="text-fg-muted">
          {__('Shows:')} {slot.shows}
        </p>
        {slot.chosen && (
          <p>
            {slot.chosen.photographer ? `Photo by ${slot.chosen.photographer} · ` : ''}
            {slot.chosen.licence}
            {slot.chosen.source && (
              <>
                {' · '}
                <a
                  href={slot.chosen.source}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="underline"
                >
                  {__('source')}
                  <span className="sr-only"> {__('(opens in a new tab)')}</span>
                </a>
              </>
            )}
          </p>
        )}
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-fg-muted">{__('Find one:')}</span>
          {slot.find.map((f) => (
            <a
              key={f.site}
              href={f.url}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1 underline"
            >
              {f.site}
              <ExternalLink className="size-3.5" aria-hidden />
              <span className="sr-only"> {__('(opens in a new tab)')}</span>
            </a>
          ))}
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            size="sm"
            variant="secondary"
            onClick={() => setOpen((o) => !o)}
            aria-expanded={open}
          >
            {slot.chosen ? 'Replace photo' : 'Add photo'}
          </Button>
          {slot.chosen && (
            <Button
              size="sm"
              variant="ghost"
              onClick={() => router.delete(url, { preserveScroll: true })}
            >
              <RotateCcw /> {__('Back to the stand-in')}
            </Button>
          )}
        </div>
        {open && (
          <form
            className="grid gap-3 rounded-lg border p-3"
            onSubmit={(e) => {
              e.preventDefault();
              form.post(url, {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => {
                  form.reset();
                  setOpen(false);
                },
              });
            }}
          >
            <Field
              label={__('Link to the photo')}
              hint={__(
                'A photo’s page or image on Nappy, Pexels or Unsplash. The server downloads it.',
              )}
              error={form.errors.link}
            >
              <Input
                type="url"
                inputMode="url"
                value={form.data.link}
                placeholder="https://nappy.co/photo/…"
                onChange={(e) => form.setData('link', e.target.value)}
              />
            </Field>
            <Field label={__('Or upload a photo')} error={form.errors.photo}>
              <Input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={(e) => form.setData('photo', e.target.files?.[0] ?? null)}
              />
            </Field>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field
                label={__('Photographer')}
                hint={__('Filled in from the photo’s page when it says.')}
                error={form.errors.photographer}
              >
                <Input
                  value={form.data.photographer}
                  maxLength={120}
                  onChange={(e) => form.setData('photographer', e.target.value)}
                />
              </Field>
              <Field label={__('Licence')} error={form.errors.licence}>
                <Select value={form.data.licence} onValueChange={(v) => form.setData('licence', v)}>
                  <SelectTrigger aria-label={__('Licence')}>
                    <SelectValue placeholder={__('Choose…')} />
                  </SelectTrigger>
                  <SelectContent>
                    {allowed.map((l) => (
                      <SelectItem key={l} value={l}>
                        {l}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            </div>
            <div>
              <Button type="submit" size="sm" loading={form.processing}>
                {__('Save demo photo')}
              </Button>
            </div>
          </form>
        )}
      </div>
    </li>
  );
}

/**
 * Admin → a design → Demo photos (Sprint 20.3): what each photo place should show, where to find a licensed photo,
 * and who took the one in use. New sites with the design get these photos, marked demo for the owner to replace.
 */
export function DemoPhotos({
  slots,
  base,
  licences,
}: {
  slots: DemoPhotoSlot[];
  base: string;
  licences: string[];
}) {
  const left = slots.filter((s) => !s.chosen).length;
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Demo photos')}</CardTitle>
        <CardDescription>
          {__(
            'Sharp, real photos make the design look finished. People and work come from licensed libraries (Nappy, Pexels, Unsplash); WURK AI only for decorative places.',
          )}{' '}
          {left > 0
            ? `${left} of ${slots.length} places still show a stand-in.`
            : 'Every place has a photo.'}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <ul className="divide-border divide-y">
          {slots.map((s) => (
            <SlotRow key={s.slot} slot={s} base={base} licences={licences} />
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
