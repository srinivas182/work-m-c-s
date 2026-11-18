import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { Field } from '@/design-system/components/field';
import { Input, Textarea } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';

interface Item {
  source: string;
  text: string | null;
  status: 'missing' | 'draft' | 'reviewed';
}
interface Props {
  locale: string;
  name: string;
  show: 'missing' | 'draft' | 'reviewed' | 'all';
  q: string;
  items: Item[];
  glossary: { id: string; term: string; translation: string; note: string | null }[];
  canManage: boolean;
}

const SHOW = {
  draft: 'Drafts to review',
  missing: 'Not translated yet',
  reviewed: 'Reviewed',
  all: 'Everything',
} as const;

function Row({ item, locale }: { item: Item; locale: string }) {
  const [text, setText] = useState(item.text ?? '');
  const save = (reviewed: boolean) =>
    router.put(
      `/settings/languages/${locale}`,
      { source: item.source, text, reviewed },
      { preserveScroll: true },
    );
  return (
    <li className="border-border grid gap-2 border-b pb-4 last:border-0">
      <p className="text-fg-muted text-sm" lang="en">
        {item.source}
      </p>
      <Textarea
        rows={2}
        lang={locale}
        aria-label={`Translation of: ${item.source}`}
        value={text}
        onChange={(e) => setText(e.target.value)}
      />
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <Button size="sm" onClick={() => save(true)} disabled={text.trim() === ''}>
          Save as reviewed
        </Button>
        {item.status !== 'reviewed' && (
          <Button
            size="sm"
            variant="ghost"
            onClick={() => save(false)}
            disabled={text.trim() === ''}
          >
            Save as a draft
          </Button>
        )}
        <span className="text-fg-muted">
          {item.status === 'reviewed'
            ? 'Reviewed'
            : item.status === 'draft'
              ? 'Draft'
              : 'Not translated yet'}
        </span>
      </div>
    </li>
  );
}

export default function LanguageTexts({
  locale,
  name,
  show,
  q,
  items,
  glossary,
  canManage,
}: Props) {
  const term = useForm({ term: '', translation: '', note: '' });
  return (
    <AppShell title={name}>
      <Head title={`${name} — Languages`} />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title={name}
          description="Review each text as an owner would read it on their phone. Keep every :placeholder exactly as it is."
        />
        <p className="text-sm">
          <Link className="underline" href="/settings/languages">
            All languages
          </Link>
        </p>
        <Card>
          <CardHeader>
            <CardTitle>Texts</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4">
            <form
              className="flex flex-wrap items-end gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                const data = new FormData(e.currentTarget);
                router.get(
                  `/settings/languages/${locale}`,
                  { show: String(data.get('show')), q: String(data.get('q')) },
                  { preserveState: false },
                );
              }}
            >
              <label className="grid gap-1 text-sm">
                Show
                <select
                  name="show"
                  defaultValue={show}
                  className="border-border bg-bg min-h-10 rounded-md border px-3"
                >
                  {Object.entries(SHOW).map(([k, v]) => (
                    <option key={k} value={k}>
                      {v}
                    </option>
                  ))}
                </select>
              </label>
              <Field label="Find">
                <Input name="q" defaultValue={q} />
              </Field>
              <Button type="submit" variant="secondary">
                Show
              </Button>
            </form>
            {items.length === 0 ? (
              <p className="text-fg-muted text-sm">Nothing here.</p>
            ) : (
              <ul className="grid gap-4">
                {items.map((i) => (
                  <Row key={i.source} item={i} locale={locale} />
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Glossary</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3 text-sm">
            <p className="text-fg-muted">Words WURK AI always translates the same way in {name}.</p>
            {glossary.length > 0 && (
              <ul className="grid gap-1">
                {glossary.map((g) => (
                  <li key={g.id}>
                    <strong>{g.term}</strong> = <span lang={locale}>{g.translation}</span>
                    {g.note ? ` (${g.note})` : ''}
                  </li>
                ))}
              </ul>
            )}
            {canManage && (
              <form
                className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                onSubmit={(e) => {
                  e.preventDefault();
                  term.post(`/settings/languages/${locale}/glossary`, {
                    preserveScroll: true,
                    onSuccess: () => term.reset(),
                  });
                }}
              >
                <Field label="English word" error={term.errors.term}>
                  <Input
                    value={term.data.term}
                    onChange={(e) => term.setData('term', e.target.value)}
                  />
                </Field>
                <Field label={`In ${name}`} error={term.errors.translation}>
                  <Input
                    lang={locale}
                    value={term.data.translation}
                    onChange={(e) => term.setData('translation', e.target.value)}
                  />
                </Field>
                <Button type="submit" variant="secondary">
                  Add
                </Button>
              </form>
            )}
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
