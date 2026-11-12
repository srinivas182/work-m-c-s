import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Card, CardContent, Checkbox, Field, Input, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Article {
  id: string;
  category_id: string;
  slug: string;
  title: string;
  summary: string | null;
  body: string;
  status: 'draft' | 'published';
  position: number;
  screens: string[];
  video: string | null;
  poster: string | null;
  url: string;
}
interface Props {
  article: Article | null;
  categories: { id: string; name: string }[];
  screens: string[];
}

/** Write or edit a help article (Markdown), its 30-second video, and the screens it helps with. */
export default function Edit({ article, categories, screens }: Props) {
  const form = useForm<{
    category_id: string;
    title: string;
    summary: string;
    body: string;
    status: string;
    position: number;
    screens: string[];
    video: File | null;
    poster: File | null;
    remove_video: boolean;
  }>({
    category_id: article?.category_id ?? categories[0]?.id ?? '',
    title: article?.title ?? '',
    summary: article?.summary ?? '',
    body: article?.body ?? '',
    status: article?.status ?? 'draft',
    position: article?.position ?? 100,
    screens: article?.screens ?? [],
    video: null,
    poster: null,
    remove_video: false,
  });
  const [find, setFind] = useState('');
  const errors = form.errors as Record<string, string | undefined>;
  const action = article
    ? window.location.pathname
    : window.location.pathname.replace(/\/new$/, '/new');
  const insertImage = async (file: File) => {
    if (!article) return;
    const data = new FormData();
    data.append('image', file);
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const r = await fetch(`${window.location.pathname}/image`, {
      method: 'POST',
      body: data,
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
    });
    if (r.ok)
      form.setData(
        'body',
        `${form.data.body}\n\n${((await r.json()) as { markdown: string }).markdown}\n`,
      );
  };
  return (
    <AppShell title={article ? article.title : 'New article'}>
      <Head title={article ? article.title : 'New article'} />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title={article ? article.title : 'New article'}
          description={
            article ? article.url : 'Write it in Markdown; add a 30-second video if it helps.'
          }
        />
        <Card>
          <CardContent className="pt-6">
            <form
              aria-label="Article"
              className="grid gap-3 text-sm"
              onSubmit={(e) => {
                e.preventDefault();
                form.post(action, { forceFormData: true, preserveScroll: true });
              }}
            >
              <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Category" error={errors.category_id}>
                  <select
                    className="border-border bg-bg h-10 w-full rounded-md border px-3"
                    value={form.data.category_id}
                    onChange={(e) => form.setData('category_id', e.target.value)}
                  >
                    {categories.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="Status">
                  <select
                    className="border-border bg-bg h-10 w-full rounded-md border px-3"
                    value={form.data.status}
                    onChange={(e) => form.setData('status', e.target.value)}
                  >
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                  </select>
                </Field>
              </div>
              <Field label="Title" error={errors.title}>
                <Input
                  value={form.data.title}
                  onChange={(e) => form.setData('title', e.target.value)}
                />
              </Field>
              <Field label="Summary (shown in search)" error={errors.summary}>
                <Input
                  value={form.data.summary}
                  onChange={(e) => form.setData('summary', e.target.value)}
                />
              </Field>
              <Field label="Article (Markdown)" error={errors.body}>
                <textarea
                  className="border-border bg-bg min-h-64 w-full rounded-md border p-3 font-mono"
                  value={form.data.body}
                  onChange={(e) => form.setData('body', e.target.value)}
                />
              </Field>
              {article && (
                <Field label="Insert an image">
                  <input
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    onChange={(e) => e.target.files?.[0] && insertImage(e.target.files[0])}
                  />
                </Field>
              )}
              <div className="grid gap-3 sm:grid-cols-2">
                <Field label="30-second video (MP4 or WebM, up to 40 MB)" error={errors.video}>
                  <input
                    type="file"
                    accept="video/mp4,video/webm"
                    onChange={(e) => form.setData('video', e.target.files?.[0] ?? null)}
                  />
                </Field>
                <Field label="Video poster image" error={errors.poster}>
                  <input
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    onChange={(e) => form.setData('poster', e.target.files?.[0] ?? null)}
                  />
                </Field>
              </div>
              {article?.video && (
                <label className="flex items-center gap-2">
                  <Checkbox
                    checked={form.data.remove_video}
                    onCheckedChange={(on) => form.setData('remove_video', on === true)}
                  />
                  Remove the video
                </label>
              )}
              <fieldset className="grid gap-2">
                <legend className="font-medium">Screens this helps with (its “?” help)</legend>
                <Input
                  aria-label="Find a screen"
                  placeholder="Find a screen, e.g. Billing"
                  value={find}
                  onChange={(e) => setFind(e.target.value)}
                />
                <div
                  className="border-border grid max-h-56 gap-1 overflow-y-auto rounded-md border p-2"
                  tabIndex={0}
                  role="region"
                  aria-label="Screens"
                >
                  {screens
                    .filter((s) => s.toLowerCase().includes(find.toLowerCase()))
                    .map((s) => (
                      <label key={s} className="flex items-center gap-2">
                        <Checkbox
                          checked={form.data.screens.includes(s)}
                          onCheckedChange={(on) =>
                            form.setData(
                              'screens',
                              on === true
                                ? [...form.data.screens, s]
                                : form.data.screens.filter((x) => x !== s),
                            )
                          }
                        />
                        {s}
                      </label>
                    ))}
                </div>
                {errors['screens.0'] && <p className="text-danger">{errors['screens.0']}</p>}
              </fieldset>
              <Button type="submit" className="justify-self-start" loading={form.processing}>
                Save
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
