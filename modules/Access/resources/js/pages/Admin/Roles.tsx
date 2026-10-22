import { Head, router, useForm } from '@inertiajs/react';
import { Lock, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Checkbox,
  Field,
  Input,
  Label,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Props {
  permissions: { key: string; label: string }[];
  system: { key: string; name: string; permissions: string[] }[];
  custom: { id: string; name: string; permissions: string[] }[];
}

export default function Roles({ permissions, system, custom }: Props) {
  const label = (k: string) => permissions.find((p) => p.key === k)?.label ?? k;
  const form = useForm<{ name: string; permissions: string[] }>({ name: '', permissions: [] });
  const toggle = (k: string, on: boolean) =>
    form.setData(
      'permissions',
      on ? [...form.data.permissions, k] : form.data.permissions.filter((p) => p !== k),
    );
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post('/roles', { preserveScroll: true, onSuccess: () => form.reset() });
  };

  return (
    <AppShell title="Staff roles">
      <Head title="Staff roles" />
      <div className="grid gap-8">
        <PageHeader
          title="Staff roles"
          description="What each WURK staff role can do. Built-in roles are always up to date; create custom roles for special cases."
        />
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          {[
            ...system.map((r) => ({ ...r, builtIn: true as const, id: r.key })),
            ...custom.map((r) => ({ ...r, builtIn: false as const })),
          ].map((r) => (
            <Card key={r.id}>
              <CardHeader>
                <div className="flex items-center justify-between gap-3">
                  <CardTitle>{r.name}</CardTitle>
                  {r.builtIn ? (
                    <Badge>
                      <Lock /> Built-in
                    </Badge>
                  ) : (
                    <Button
                      variant="ghost"
                      size="icon-sm"
                      aria-label={`Delete ${r.name}`}
                      onClick={() => router.delete(`/roles/${r.id}`, { preserveScroll: true })}
                    >
                      <Trash2 />
                    </Button>
                  )}
                </div>
              </CardHeader>
              <CardContent>
                <ul className="grid gap-1.5 text-sm">
                  {r.permissions.map((p) => (
                    <li key={p}>{label(p)}</li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          ))}
        </div>
        <Card>
          <CardHeader>
            <CardTitle>New custom role</CardTitle>
            <CardDescription>Choose exactly what this role allows.</CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={submit} className="grid gap-5">
              <Field label="Role name" error={form.errors.name} required className="sm:max-w-sm">
                <Input
                  value={form.data.name}
                  onChange={(e) => form.setData('name', e.target.value)}
                />
              </Field>
              <fieldset className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <legend className="mb-2 text-sm font-medium">Permissions</legend>
                {permissions.map((p) => (
                  <div key={p.key} className="flex items-center gap-3">
                    <Checkbox
                      id={`perm-${p.key}`}
                      checked={form.data.permissions.includes(p.key)}
                      onCheckedChange={(v) => toggle(p.key, v === true)}
                    />
                    <Label htmlFor={`perm-${p.key}`} className="font-normal">
                      {p.label}
                    </Label>
                  </div>
                ))}
              </fieldset>
              {form.errors.permissions && (
                <p className="text-danger-11 text-sm" role="alert">
                  {form.errors.permissions}
                </p>
              )}
              <Button type="submit" className="w-fit" loading={form.processing}>
                <Plus /> Create role
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
