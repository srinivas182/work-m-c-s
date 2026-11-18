import { Head, Link, router, useForm } from '@inertiajs/react';
import {
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  ExternalLink,
  Home,
  PencilLine,
  Plus,
  Rocket,
  Trash2,
} from 'lucide-react';
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
import { Dialog, DialogContent, DialogFooter } from '@/design-system/components/dialog';
import { Field } from '@/design-system/components/field';
import { Input, Textarea } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/design-system/components/select';
import AppShell from '@/layouts/AppShell';
import { DemoPhotos, type DemoPhotoSlot } from '../../admin/DemoPhotos';
import { __ } from '@/i18n';

interface PageRow {
  key: string;
  title: string;
  slug: string;
  is_home: boolean;
  sections?: number;
}

interface MenuRow {
  label: string;
  type: 'page' | 'link';
  page?: string;
  href?: string;
}

interface Props {
  template: {
    key: string;
    name: string;
    description: string | null;
    status: string;
    version: number | null;
    category: string | null;
    revision: number;
  };
  pages: PageRow[];
  menus: { header: MenuRow[]; footer: MenuRow[] };
  branding: { primary: string; secondary: string | null; font_pairing: string; corners: string };
  versions: { id: string; number: number; note: string | null; created_at: string }[];
  fontPairings: Record<string, { label: string }>;
  /** Sprint 20.3: the design's photo places and HQ's demo photos. */
  demoPhotos?: DemoPhotoSlot[];
  licences?: string[];
}

const move = <T,>(list: T[], i: number, d: number): T[] => {
  const next = [...list];
  const [x] = next.splice(i, 1);
  if (x !== undefined) next.splice(i + d, 0, x);
  return next;
};

export default function Template({
  template,
  pages,
  menus,
  branding,
  versions,
  fontPairings,
  demoPhotos = [],
  licences = [],
}: Props) {
  const base = `/templates/${template.key}`;
  const form = useForm({
    name: template.name,
    description: template.description ?? '',
    revision: template.revision,
    pages: pages.map((p) => ({ key: p.key, title: p.title, slug: p.slug, is_home: p.is_home })),
    menus,
    branding,
  });
  const [publishing, setPublishing] = useState(false);
  const [note, setNote] = useState('');
  const errors = form.errors as Record<string, string>;
  // Inertia's typed setData is awkward with nested values; this keeps the call sites readable.
  const set = <K extends keyof typeof form.data>(k: K, v: (typeof form.data)[K]) =>
    form.setData(k as never, v as never);
  const dirty = form.isDirty;

  const menuEditor = (where: 'header' | 'footer') => (
    <div className="grid gap-2">
      <p className="text-sm font-medium">{where === 'header' ? 'Header menu' : 'Footer menu'}</p>
      {form.data.menus[where].map((item, i) => (
        <div key={i} className="grid gap-2 sm:grid-cols-[1fr_8rem_1fr_auto]">
          <Input
            aria-label={__('Menu label')}
            value={item.label}
            onChange={(e) =>
              set('menus', {
                ...form.data.menus,
                [where]: form.data.menus[where].map((x, j) =>
                  j === i ? { ...x, label: e.target.value } : x,
                ),
              })
            }
          />
          <Select
            value={item.type}
            onValueChange={(v) =>
              set('menus', {
                ...form.data.menus,
                [where]: form.data.menus[where].map((x, j) =>
                  j === i ? { ...x, type: v as MenuRow['type'] } : x,
                ),
              })
            }
          >
            <SelectTrigger aria-label={__('Links to')}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="page">{__('A page')}</SelectItem>
              <SelectItem value="link">{__('A web address')}</SelectItem>
            </SelectContent>
          </Select>
          {item.type === 'page' ? (
            <Select
              value={item.page ?? ''}
              onValueChange={(v) =>
                set('menus', {
                  ...form.data.menus,
                  [where]: form.data.menus[where].map((x, j) => (j === i ? { ...x, page: v } : x)),
                })
              }
            >
              <SelectTrigger aria-label={__('Page')}>
                <SelectValue placeholder={__('Choose a page')} />
              </SelectTrigger>
              <SelectContent>
                {form.data.pages.map((p) => (
                  <SelectItem key={p.key} value={p.key}>
                    {p.title}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          ) : (
            <Input
              aria-label={__('Web address')}
              value={item.href ?? ''}
              placeholder="https://…, #section"
              onChange={(e) =>
                set('menus', {
                  ...form.data.menus,
                  [where]: form.data.menus[where].map((x, j) =>
                    j === i ? { ...x, href: e.target.value } : x,
                  ),
                })
              }
            />
          )}
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={`Remove ${item.label || 'link'}`}
            onClick={() =>
              set('menus', {
                ...form.data.menus,
                [where]: form.data.menus[where].filter((_, j) => j !== i),
              })
            }
          >
            <Trash2 />
          </Button>
        </div>
      ))}
      <Button
        size="sm"
        variant="ghost"
        className="w-fit"
        onClick={() =>
          set('menus', {
            ...form.data.menus,
            [where]: [
              ...form.data.menus[where],
              { label: '', type: 'page', page: form.data.pages[0]?.key },
            ],
          })
        }
      >
        <Plus /> {__('Add a link')}
      </Button>
    </div>
  );

  return (
    <AppShell title={template.name}>
      <Head title={template.name} />
      <div className="grid max-w-5xl gap-8">
        <Link
          href="/templates"
          className="text-fg-muted hover:text-fg inline-flex w-fit items-center gap-1.5 text-sm font-medium"
        >
          <ArrowLeft className="size-4" aria-hidden /> {__('Templates')}
        </Link>
        <PageHeader
          title={template.name}
          eyebrow={template.category ?? undefined}
          actions={
            <div className="flex flex-wrap gap-2">
              <Button variant="ghost" asChild>
                <a href={`${base}/preview`} target="_blank" rel="noopener">
                  <ExternalLink /> {__('Preview draft')}
                </a>
              </Button>
              <Button
                variant="secondary"
                disabled={dirty}
                loading={form.processing}
                onClick={() => setPublishing(true)}
              >
                <Rocket /> {__('Publish')}
              </Button>
            </div>
          }
        />
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <Badge
            tone={
              template.status === 'published'
                ? 'success'
                : template.status === 'retired'
                  ? 'neutral'
                  : 'warning'
            }
          >
            {template.status.charAt(0).toUpperCase() + template.status.slice(1)}
          </Badge>
          {template.version !== null && (
            <span className="text-fg-muted">
              {__('Businesses get version')} {template.version}
            </span>
          )}
          {dirty && (
            <span className="text-fg-muted">
              {__('· Unsaved changes — save before publishing')}
            </span>
          )}
        </div>

        <Card>
          <CardHeader>
            <CardTitle>{__('Details')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4">
            <Field label={__('Name')} error={errors.name} required>
              <Input value={form.data.name} onChange={(e) => set('name', e.target.value)} />
            </Field>
            <Field label={__('Short description')} error={errors.description}>
              <Textarea
                rows={2}
                value={form.data.description}
                onChange={(e) => set('description', e.target.value)}
              />
            </Field>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Pages')}</CardTitle>
            <CardDescription>
              {__(
                'Edit each page in the visual editor. Save here after adding, removing or renaming pages.',
              )}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-2">
            {form.data.pages.map((p, i) => (
              <div
                key={p.key}
                className="border-border grid items-center gap-2 rounded-xl border p-2 sm:grid-cols-[1fr_1fr_auto]"
              >
                <Input
                  aria-label={__('Page title')}
                  value={p.title}
                  onChange={(e) =>
                    set(
                      'pages',
                      form.data.pages.map((x, j) =>
                        j === i ? { ...x, title: e.target.value } : x,
                      ),
                    )
                  }
                />
                <Input
                  aria-label={__('Web address')}
                  value={p.slug}
                  disabled={p.is_home}
                  onChange={(e) =>
                    set(
                      'pages',
                      form.data.pages.map((x, j) => (j === i ? { ...x, slug: e.target.value } : x)),
                    )
                  }
                />
                <div className="flex items-center justify-end gap-0.5">
                  {p.is_home ? (
                    <Badge tone="brand">{__('Home')}</Badge>
                  ) : (
                    <Button
                      variant="ghost"
                      size="icon-sm"
                      aria-label={`Make ${p.title} the home page`}
                      onClick={() =>
                        set(
                          'pages',
                          form.data.pages.map((x, j) => ({ ...x, is_home: j === i })),
                        )
                      }
                    >
                      <Home />
                    </Button>
                  )}
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`Move ${p.title} up`}
                    disabled={i === 0}
                    onClick={() => set('pages', move(form.data.pages, i, -1))}
                  >
                    <ArrowUp />
                  </Button>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`Move ${p.title} down`}
                    disabled={i === form.data.pages.length - 1}
                    onClick={() => set('pages', move(form.data.pages, i, 1))}
                  >
                    <ArrowDown />
                  </Button>
                  {!p.is_home && (
                    <Button
                      variant="ghost"
                      size="icon-sm"
                      aria-label={`Remove ${p.title}`}
                      onClick={() =>
                        set(
                          'pages',
                          form.data.pages.filter((_, j) => j !== i),
                        )
                      }
                    >
                      <Trash2 />
                    </Button>
                  )}
                  {pages.some((saved) => saved.key === p.key) && (
                    <Button size="sm" variant="secondary" disabled={dirty} asChild={!dirty}>
                      {dirty ? (
                        <span>{__('Edit')}</span>
                      ) : (
                        <Link href={`${base}/pages/${p.key}/edit`} aria-label={`Edit ${p.title}`}>
                          <PencilLine /> {__('Edit')}
                        </Link>
                      )}
                    </Button>
                  )}
                </div>
              </div>
            ))}
            <Button
              size="sm"
              variant="ghost"
              className="w-fit"
              onClick={() => {
                const n = form.data.pages.length + 1;
                set('pages', [
                  ...form.data.pages,
                  { key: `page-${n}`, title: `Page ${n}`, slug: `page-${n}`, is_home: false },
                ]);
              }}
            >
              <Plus /> {__('Add a page')}
            </Button>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Branding')}</CardTitle>
            <CardDescription>
              {__(
                'Owners keep their own colours and logo when they switch designs; fonts and corners come from the design.',
              )}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-2">
            <Field label={__('Main colour')} error={errors['branding.primary']}>
              <Input
                value={form.data.branding.primary}
                onChange={(e) =>
                  set('branding', { ...form.data.branding, primary: e.target.value })
                }
                className="font-mono uppercase"
              />
            </Field>
            <Field label={__('Second colour (optional)')}>
              <Input
                value={form.data.branding.secondary ?? ''}
                onChange={(e) =>
                  set('branding', { ...form.data.branding, secondary: e.target.value || null })
                }
                className="font-mono uppercase"
              />
            </Field>
            <Field label={__('Fonts')}>
              {(control) => (
                <Select
                  value={form.data.branding.font_pairing}
                  onValueChange={(v) => set('branding', { ...form.data.branding, font_pairing: v })}
                >
                  <SelectTrigger {...control}>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {Object.entries(fontPairings).map(([k, p]) => (
                      <SelectItem key={k} value={k}>
                        {p.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </Field>
            <Field label={__('Corners')}>
              {(control) => (
                <Select
                  value={form.data.branding.corners}
                  onValueChange={(v) => set('branding', { ...form.data.branding, corners: v })}
                >
                  <SelectTrigger {...control}>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {['square', 'soft', 'round'].map((c) => (
                      <SelectItem key={c} value={c}>
                        {c.charAt(0).toUpperCase() + c.slice(1)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </Field>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Menus')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-6">
            {menuEditor('header')}
            {menuEditor('footer')}
          </CardContent>
        </Card>

        {(errors.definition || errors.revision) && (
          <p role="alert" className="text-danger-11 text-sm">
            {errors.definition ?? errors.revision}
          </p>
        )}
        <div className="flex flex-wrap gap-3">
          <Button
            loading={form.processing}
            disabled={!dirty}
            onClick={() => form.put(base, { preserveScroll: true })}
          >
            {__('Save template')}
          </Button>
          {template.status !== 'retired' && (
            <Button
              variant="ghost"
              onClick={() => router.post(`${base}/retire`, {}, { preserveScroll: true })}
            >
              {__('Retire template')}
            </Button>
          )}
        </div>

        {demoPhotos.length > 0 && <DemoPhotos slots={demoPhotos} base={base} licences={licences} />}

        {versions.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>{__('Published versions')}</CardTitle>
            </CardHeader>
            <CardContent>
              <ul className="divide-border divide-y">
                {versions.map((v) => (
                  <li key={v.id} className="py-2.5 text-sm">
                    <span className="font-semibold">
                      {__('Version')} {v.number}
                    </span>{' '}
                    <span className="text-fg-muted">
                      · {new Date(v.created_at).toLocaleString()}
                      {v.note ? ` · ${v.note}` : ''}
                    </span>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}
      </div>

      {publishing && (
        <Dialog open onOpenChange={(o) => !o && setPublishing(false)}>
          <DialogContent
            title={`Publish ${template.name}`}
            description={__(
              'New businesses receive this version straight away. Businesses already using the design keep their sites.',
            )}
          >
            <form
              className="grid gap-4"
              onSubmit={(e) => {
                e.preventDefault();
                router.post(
                  `${base}/publish`,
                  { note: note || null },
                  { preserveScroll: true, onSuccess: () => setPublishing(false) },
                );
              }}
            >
              <Field label={__('What changed? (optional)')}>
                <Input value={note} maxLength={255} onChange={(e) => setNote(e.target.value)} />
              </Field>
              <DialogFooter>
                <Button type="submit">{__('Publish now')}</Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      )}
    </AppShell>
  );
}
