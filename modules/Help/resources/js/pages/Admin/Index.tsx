import { Head, Link, useForm } from '@inertiajs/react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Props {
  categories: {
    id: string;
    slug: string;
    name: string;
    summary: string | null;
    position: number;
  }[];
  articles: {
    id: string;
    title: string;
    status: string;
    category_id: string;
    views: number;
    helpful_yes: number;
    helpful_no: number;
    video: boolean;
    screens: number;
  }[];
  notFound: { query: string; count: number }[];
}

/** Admin → Help centre. */
export default function Index({ categories, articles, notFound }: Props) {
  const cat = useForm({ name: '', summary: '' });
  const name = Object.fromEntries(categories.map((c) => [c.id, c.name]));
  return (
    <AppShell title={__('Help centre')}>
      <Head title={__('Help centre')} />
      <div className="grid max-w-5xl gap-6">
        <PageHeader
          title={__('Help centre')}
          description={__(
            'Articles and 30-second videos for help.wurk.co.za and the “?” on every screen.',
          )}
          actions={
            <Button asChild>
              <Link href={`${window.location.pathname.replace(/\/$/, '')}/new`}>
                {__('New article')}
              </Link>
            </Button>
          }
        />
        <Card>
          <CardHeader>
            <CardTitle>{__('Categories')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3 text-sm">
            <ul className="grid gap-1">
              {categories.map((c) => (
                <li key={c.id}>
                  <strong>{c.name}</strong>{' '}
                  {c.summary && <span className="text-fg-muted">— {c.summary}</span>}
                </li>
              ))}
            </ul>
            <form
              aria-label={__('New category')}
              className="flex flex-wrap items-end gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                cat.post(`${window.location.pathname.replace(/\/$/, '')}/categories`, {
                  preserveScroll: true,
                  onSuccess: () => cat.reset(),
                });
              }}
            >
              <Field label={__('Category name')} error={cat.errors.name}>
                <Input
                  value={cat.data.name}
                  onChange={(e) => cat.setData('name', e.target.value)}
                />
              </Field>
              <Field label={__('Short description')}>
                <Input
                  value={cat.data.summary}
                  onChange={(e) => cat.setData('summary', e.target.value)}
                />
              </Field>
              <Button type="submit" variant="secondary" loading={cat.processing}>
                {__('Add category')}
              </Button>
            </form>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Articles')}</CardTitle>
          </CardHeader>
          <CardContent>
            <ul aria-label={__('Articles')} className="grid gap-2 text-sm">
              {articles.map((a) => {
                const votes = a.helpful_yes + a.helpful_no;
                return (
                  <li
                    key={a.id}
                    className="border-border flex flex-wrap items-center gap-3 rounded-lg border p-3"
                  >
                    <Link
                      className="font-semibold underline"
                      href={`${window.location.pathname.replace(/\/$/, '')}/${a.id}`}
                    >
                      {a.title}
                    </Link>
                    <Badge tone={a.status === 'published' ? 'success' : 'neutral'}>
                      {a.status === 'published' ? 'Published' : 'Draft'}
                    </Badge>
                    <span className="text-fg-muted">
                      {name[a.category_id]} · {a.views} {__('views ·')}{' '}
                      {votes === 0
                        ? 'no votes yet'
                        : `${Math.round((a.helpful_yes / votes) * 100)}% found it helpful`}
                      {a.video ? ' · video' : ''} · {a.screens}{' '}
                      {a.screens === 1 ? 'screen' : 'screens'}
                    </span>
                  </li>
                );
              })}
            </ul>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Searches that found nothing (last 30 days)')}</CardTitle>
          </CardHeader>
          <CardContent className="text-sm">
            {notFound.length === 0 ? (
              <p className="text-fg-muted">{__('None — or nobody has searched yet.')}</p>
            ) : (
              <ul aria-label={__('Searches that found nothing')} className="grid gap-1">
                {notFound.map((s) => (
                  <li key={s.query}>
                    “{s.query}” — {s.count} {s.count === 1 ? 'time' : 'times'}
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
