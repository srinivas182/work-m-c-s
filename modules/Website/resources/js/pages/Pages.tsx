import { Head, router, useForm } from '@inertiajs/react';
import {
  ArrowDown,
  ArrowUp,
  FilePlus2,
  Home,
  MoreHorizontal,
  RotateCcw,
  Trash2,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Dialog,
  DialogContent,
  DialogFooter,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  Field,
  Input,
  PageHeader,
  Textarea,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface PageRow {
  id: string;
  title: string;
  slug: string;
  is_home: boolean;
  published: boolean;
  updated_at: string | null;
}

interface Props {
  pages: PageRow[];
  deleted: { id: string; title: string; deleted_at: string | null }[];
  canPublish: boolean;
  /** Absolute path of the pages endpoints, e.g. /glow-salon/website/pages */
  base: string;
}

function AddPage({ base, onClose }: { base: string; onClose: () => void }) {
  const form = useForm({ title: '', slug: '' });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post(base, { preserveScroll: true, onSuccess: onClose });
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent title="Add a page" description="Start empty; add sections in the editor.">
        <form onSubmit={submit} className="grid gap-4" noValidate>
          <Field label="Page title" error={form.errors.title} required>
            <Input
              value={form.data.title}
              onChange={(e) => form.setData('title', e.target.value)}
              autoFocus
            />
          </Field>
          <Field label="Web address" hint="Leave empty to use the title" error={form.errors.slug}>
            <Input
              value={form.data.slug}
              onChange={(e) => form.setData('slug', e.target.value)}
              placeholder="services"
            />
          </Field>
          <DialogFooter>
            <Button type="submit" loading={form.processing}>
              Add page
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function PageSettings({
  base,
  page,
  onClose,
}: {
  base: string;
  page: PageRow;
  onClose: () => void;
}) {
  const form = useForm({ title: page.title, slug: page.slug, seo_title: '', seo_description: '' });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.put(`${base}/${page.id}`, { preserveScroll: true, onSuccess: onClose });
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent
        title={`${page.title} — settings`}
        description="Search engines show the SEO title and description."
      >
        <form onSubmit={submit} className="grid gap-4" noValidate>
          <Field label="Page title" error={form.errors.title} required>
            <Input
              value={form.data.title}
              onChange={(e) => form.setData('title', e.target.value)}
            />
          </Field>
          <Field
            label="Web address"
            hint={page.is_home ? 'The home page always lives at your main address' : undefined}
            error={form.errors.slug}
          >
            <Input
              value={form.data.slug}
              onChange={(e) => form.setData('slug', e.target.value)}
              disabled={page.is_home}
            />
          </Field>
          <Field label="SEO title" hint="Up to 70 characters" error={form.errors.seo_title}>
            <Input
              value={form.data.seo_title}
              onChange={(e) => form.setData('seo_title', e.target.value)}
              maxLength={70}
            />
          </Field>
          <Field
            label="SEO description"
            hint="Up to 170 characters"
            error={form.errors.seo_description}
          >
            <Textarea
              rows={3}
              value={form.data.seo_description}
              onChange={(e) => form.setData('seo_description', e.target.value)}
              maxLength={170}
            />
          </Field>
          <DialogFooter>
            <Button type="submit" loading={form.processing}>
              Save
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export default function Pages({ pages, deleted, base }: Props) {
  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState<PageRow | null>(null);
  const move = (index: number, delta: number) => {
    const order = pages.map((p) => p.id);
    const [moved] = order.splice(index, 1);
    if (moved === undefined) return;
    order.splice(index + delta, 0, moved);
    router.put(`${base}/order`, { order }, { preserveScroll: true });
  };

  return (
    <AppShell title="Website">
      <Head title="Website" />
      <div className="grid max-w-4xl gap-8">
        <PageHeader
          title="Website pages"
          description="Add pages, choose your home page and put them in order. The visual editor and live site preview arrive next."
          actions={
            <Button onClick={() => setAdding(true)}>
              <FilePlus2 /> Add page
            </Button>
          }
        />
        <Card>
          <CardContent className="p-0">
            <ul className="divide-border divide-y" aria-label="Pages">
              {pages.map((p, i) => (
                <li
                  key={p.id}
                  className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                >
                  <div className="min-w-0">
                    <p className="flex items-center gap-2 font-medium">
                      {p.is_home && <Home className="text-primary size-4" aria-label="Home page" />}
                      {p.title}
                    </p>
                    <p className="text-fg-muted truncate text-sm">/{p.is_home ? '' : p.slug}</p>
                  </div>
                  <div className="flex items-center gap-1">
                    <Badge tone={p.published ? 'success' : 'neutral'} dot>
                      {p.published ? 'Published' : 'Draft'}
                    </Badge>
                    <Button
                      variant="ghost"
                      size="icon-sm"
                      aria-label={`Move ${p.title} up`}
                      disabled={i === 0}
                      onClick={() => move(i, -1)}
                    >
                      <ArrowUp />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon-sm"
                      aria-label={`Move ${p.title} down`}
                      disabled={i === pages.length - 1}
                      onClick={() => move(i, 1)}
                    >
                      <ArrowDown />
                    </Button>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          aria-label={`More actions for ${p.title}`}
                        >
                          <MoreHorizontal />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={() => setEditing(p)}>Settings</DropdownMenuItem>
                        {!p.is_home && (
                          <DropdownMenuItem
                            onSelect={() =>
                              router.post(`${base}/${p.id}/home`, {}, { preserveScroll: true })
                            }
                          >
                            <Home /> Make home page
                          </DropdownMenuItem>
                        )}
                        {!p.is_home && (
                          <DropdownMenuItem
                            destructive
                            onSelect={() =>
                              router.delete(`${base}/${p.id}`, { preserveScroll: true })
                            }
                          >
                            <Trash2 /> Delete
                          </DropdownMenuItem>
                        )}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
        {deleted.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Recently deleted</CardTitle>
            </CardHeader>
            <CardContent>
              <ul className="divide-border divide-y">
                {deleted.map((d) => (
                  <li key={d.id} className="flex items-center justify-between gap-3 py-3">
                    <span>
                      {d.title}{' '}
                      <span className="text-fg-muted text-sm">
                        · deleted {d.deleted_at ? new Date(d.deleted_at).toLocaleDateString() : ''}
                      </span>
                    </span>
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() =>
                        router.post(`${base}/${d.id}/restore`, {}, { preserveScroll: true })
                      }
                    >
                      <RotateCcw /> Restore
                    </Button>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}
      </div>
      {adding && <AddPage base={base} onClose={() => setAdding(false)} />}
      {editing && <PageSettings base={base} page={editing} onClose={() => setEditing(null)} />}
    </AppShell>
  );
}
