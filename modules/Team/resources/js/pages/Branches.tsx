import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Branch {
  id: string;
  name: string;
  address: string | null;
  phone: string | null;
  main: boolean;
  closed: boolean;
}

function BranchForm({ b, base, done }: { b?: Branch; base: string; done: () => void }) {
  const form = useForm({ name: b?.name ?? '', address: b?.address ?? '', phone: b?.phone ?? '' });
  return (
    <form
      aria-label={b ? `Edit ${b.name}` : 'Add a branch'}
      className="grid gap-3 sm:grid-cols-3 sm:items-end"
      onSubmit={(e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => (b ? done() : form.reset()) };
        if (b) form.put(`${base}/${b.id}`, options);
        else form.post(base, options);
      }}
    >
      <Field label="Name" error={form.errors.name}>
        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
      </Field>
      <Field label="Address" error={form.errors.address}>
        <Input
          value={form.data.address}
          onChange={(e) => form.setData('address', e.target.value)}
        />
      </Field>
      <Field label="Phone" error={form.errors.phone}>
        <Input
          type="tel"
          value={form.data.phone}
          onChange={(e) => form.setData('phone', e.target.value)}
        />
      </Field>
      <Button type="submit" className="justify-self-start" loading={form.processing}>
        {b ? 'Save branch' : 'Add branch'}
      </Button>
    </form>
  );
}

export default function Branches({
  branches,
  limit,
  open,
  canManage,
}: {
  branches: Branch[];
  limit: number;
  open: number;
  canManage: boolean;
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  const [editing, setEditing] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  return (
    <AppShell title="Branches">
      <Head title="Branches" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Branches"
          description={`${open} of ${limit} ${limit === 1 ? 'branch' : 'branches'} open on your package. Each branch can have its own team, hours, stock and payment accounts.`}
        />
        {open > 1 && (
          <p className="text-sm">
            <a
              className="underline"
              href={`${window.location.pathname.replace(/\/$/, '')}/settlements`}
            >
              Between branches — what one branch owes another
            </a>
          </p>
        )}
        {error && (
          <p role="alert" className="text-danger text-sm">
            {error}
          </p>
        )}
        <ul className="grid gap-3" aria-label="Branches">
          {branches.map((b) => (
            <li key={b.id}>
              <Card>
                <CardHeader>
                  <CardTitle className="flex flex-wrap items-center gap-2">
                    {b.name}
                    {b.main && <Badge tone="neutral">Main</Badge>}
                    {b.closed && <Badge tone="warning">Closed</Badge>}
                  </CardTitle>
                </CardHeader>
                <CardContent className="grid gap-3 text-sm">
                  <p className="text-fg-muted">
                    {[b.address, b.phone].filter(Boolean).join(' · ') || 'No address or phone yet'}
                  </p>
                  {canManage && (
                    <div className="flex flex-wrap gap-2">
                      <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => setEditing(editing === b.id ? null : b.id)}
                      >
                        Edit
                      </Button>
                      {!b.main && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() =>
                            router.put(
                              `${base}/${b.id}/closed`,
                              { closed: !b.closed },
                              {
                                preserveScroll: true,
                                onError: (e) => setError(Object.values(e)[0] ?? null),
                                onSuccess: () => setError(null),
                              },
                            )
                          }
                        >
                          {b.closed ? 'Reopen' : 'Close branch'}
                        </Button>
                      )}
                    </div>
                  )}
                  {editing === b.id && (
                    <BranchForm b={b} base={base} done={() => setEditing(null)} />
                  )}
                </CardContent>
              </Card>
            </li>
          ))}
        </ul>
        {canManage && (
          <Card>
            <CardHeader>
              <CardTitle>Add a branch</CardTitle>
            </CardHeader>
            <CardContent>
              <BranchForm base={base} done={() => undefined} />
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
