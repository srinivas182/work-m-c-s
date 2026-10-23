import { Head, Link, router, useForm } from '@inertiajs/react';
import { LayoutTemplate, Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Dialog,
  DialogContent,
  DialogFooter,
  Field,
  Input,
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Textarea,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Category {
  id: string;
  key: string;
  name: string;
  description: string | null;
  active: boolean;
  templates: { key: string; name: string; status: string; version: number | null }[];
}

interface Props {
  categories: Category[];
  allTemplates: { key: string; name: string }[];
}

const STATUS: Record<string, ['success' | 'neutral' | 'warning', string]> = {
  published: ['success', 'Published'],
  draft: ['warning', 'Draft'],
  retired: ['neutral', 'Retired'],
};

function NewTemplate({
  categories,
  allTemplates,
  initial,
  onClose,
}: Props & { initial: string; onClose: () => void }) {
  const form = useForm({ category: initial, name: '', description: '', copy_from: '' });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.transform((d) => ({ ...d, copy_from: d.copy_from || null }));
    form.post('/templates', { onSuccess: onClose });
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent
        title="New template"
        description="Start blank, or copy an existing design and change it."
      >
        <form onSubmit={submit} className="grid gap-4" noValidate>
          <Field label="Category" error={form.errors.category}>
            {(control) => (
              <Select value={form.data.category} onValueChange={(v) => form.setData('category', v)}>
                <SelectTrigger {...control}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {categories.map((c) => (
                    <SelectItem key={c.key} value={c.key}>
                      {c.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </Field>
          <Field label="Name" hint="e.g. Hair & braids salon" error={form.errors.name} required>
            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
          </Field>
          <Field
            label="Short description"
            hint="Shown to owners when they choose a design"
            error={form.errors.description}
          >
            <Textarea
              rows={2}
              value={form.data.description}
              onChange={(e) => form.setData('description', e.target.value)}
            />
          </Field>
          <Field label="Copy from (optional)" error={form.errors.copy_from}>
            {(control) => (
              <Select
                value={form.data.copy_from || 'none'}
                onValueChange={(v) => form.setData('copy_from', v === 'none' ? '' : v)}
              >
                <SelectTrigger {...control}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Start blank</SelectItem>
                  {allTemplates.map((t) => (
                    <SelectItem key={t.key} value={t.key}>
                      {t.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </Field>
          <DialogFooter>
            <Button type="submit" loading={form.processing}>
              Create template
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export default function Templates({ categories, allTemplates }: Props) {
  const [creating, setCreating] = useState<string | null>(null);
  return (
    <AppShell title="Templates">
      <Head title="Templates" />
      <div className="grid gap-8">
        <PageHeader
          title="Website templates"
          description="Designs businesses choose at signup. A category appears in signup once it has a published template."
          actions={
            <Button onClick={() => setCreating(categories[0]?.key ?? '')}>
              <Plus /> New template
            </Button>
          }
        />
        <div className="grid gap-4 lg:grid-cols-2">
          {categories.map((c) => (
            <Card key={c.key}>
              <CardHeader>
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <CardTitle>{c.name}</CardTitle>
                    {c.description && <CardDescription>{c.description}</CardDescription>}
                  </div>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                      router.put(
                        `/templates/categories/${c.key}`,
                        { name: c.name, description: c.description, active: !c.active },
                        { preserveScroll: true },
                      )
                    }
                  >
                    {c.active ? 'Hide from signup' : 'Show in signup'}
                  </Button>
                </div>
              </CardHeader>
              <CardContent className="grid gap-2">
                {!c.active && <Badge>Hidden from signup</Badge>}
                {c.templates.length === 0 && (
                  <p className="text-fg-muted text-sm">No templates yet.</p>
                )}
                {c.templates.map((t) => {
                  const [tone, label] = STATUS[t.status] ?? ['neutral', t.status];
                  return (
                    <Link
                      key={t.key}
                      href={`/templates/${t.key}`}
                      className="border-border hover:bg-sunken flex items-center justify-between gap-3 rounded-xl border px-3 py-2.5"
                    >
                      <span className="flex items-center gap-2 font-medium">
                        <LayoutTemplate className="text-fg-muted size-4" aria-hidden />
                        {t.name}
                      </span>
                      <span className="text-fg-muted flex items-center gap-2 text-sm">
                        {t.version !== null && `v${t.version}`}
                        <Badge tone={tone}>{label}</Badge>
                      </span>
                    </Link>
                  );
                })}
                <Button
                  size="sm"
                  variant="secondary"
                  className="w-fit"
                  onClick={() => setCreating(c.key)}
                >
                  <Plus /> Add a template
                </Button>
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
      {creating !== null && (
        <NewTemplate
          categories={categories}
          allTemplates={allTemplates}
          initial={creating}
          onClose={() => setCreating(null)}
        />
      )}
    </AppShell>
  );
}
