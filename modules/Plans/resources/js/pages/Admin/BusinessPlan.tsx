import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
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
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Textarea,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface ModuleRow {
  key: string;
  name: string;
  installed: boolean;
  enabled: boolean;
  override: { granted: boolean; reason: string } | null;
}

interface Props {
  business: { id: string; name: string };
  subscription: {
    package: string | null;
    package_id: string;
    version: number | null;
    status: string;
    trial_ends_at: string | null;
    latest: boolean;
  } | null;
  packages: { id: string; name: string }[];
  modules: ModuleRow[];
  canManage: boolean;
}

function OverrideDialog({
  business,
  row,
  onClose,
}: {
  business: Props['business'];
  row: ModuleRow;
  onClose: () => void;
}) {
  const form = useForm({ module: row.key, state: row.enabled ? 'revoke' : 'grant', reason: '' });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post(`/businesses/${business.id}/plan/modules`, {
      preserveScroll: true,
      onSuccess: onClose,
    });
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent
        title={`${row.enabled ? 'Remove' : 'Give'} ${row.name}`}
        description="An exception to this business’s package, recorded in the audit log."
      >
        <form onSubmit={submit}>
          <Field label="Reason" error={form.errors.reason} required>
            <Textarea
              value={form.data.reason}
              onChange={(e) => form.setData('reason', e.target.value)}
            />
          </Field>
          <DialogFooter>
            {row.override && (
              <Button
                type="button"
                variant="secondary"
                onClick={() => {
                  form.setData('state', 'package');
                  form.post(`/businesses/${business.id}/plan/modules`, {
                    preserveScroll: true,
                    onSuccess: onClose,
                  });
                }}
              >
                Follow the package again
              </Button>
            )}
            <Button type="submit" loading={form.processing}>
              {row.enabled ? 'Remove access' : 'Give access'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export default function BusinessPlan({
  business,
  subscription,
  packages,
  modules,
  canManage,
}: Props) {
  const form = useForm({ package_id: subscription?.package_id ?? '' });
  const [editing, setEditing] = useState<ModuleRow | null>(null);

  return (
    <AppShell title={`${business.name} — plan`}>
      <Head title={`${business.name} — plan`} />
      <div className="grid max-w-4xl gap-6">
        <Link
          href={`/businesses/${business.id}`}
          className="text-fg-muted hover:text-fg inline-flex w-fit items-center gap-1.5 text-sm font-medium"
        >
          <ArrowLeft className="size-4" aria-hidden /> {business.name}
        </Link>
        <PageHeader title="Plan and modules" eyebrow={business.name} />
        <Card>
          <CardHeader>
            <CardTitle>Package</CardTitle>
            <CardDescription>
              {subscription
                ? `${subscription.package} · version ${subscription.version}${subscription.latest ? '' : ' (older terms — kept until they change package)'}`
                : 'No package yet.'}
            </CardDescription>
          </CardHeader>
          {canManage && (
            <CardContent>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  form.put(`/businesses/${business.id}/plan`, { preserveScroll: true });
                }}
                className="flex flex-col gap-3 sm:flex-row sm:items-end"
              >
                <Field label="Change package" className="sm:w-80">
                  {(control) => (
                    <Select
                      value={form.data.package_id}
                      onValueChange={(v) => form.setData('package_id', v)}
                    >
                      <SelectTrigger {...control}>
                        <SelectValue placeholder="Choose a package" />
                      </SelectTrigger>
                      <SelectContent>
                        {packages.map((p) => (
                          <SelectItem key={p.id} value={p.id}>
                            {p.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                </Field>
                <Button type="submit" loading={form.processing} disabled={!form.data.package_id}>
                  Apply
                </Button>
              </form>
            </CardContent>
          )}
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Modules</CardTitle>
            <CardDescription>
              From the package, plus any exceptions you set for this business.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ul className="divide-border divide-y">
              {modules.map((m) => (
                <li
                  key={m.key}
                  className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                >
                  <span>
                    <span className="font-medium">{m.name}</span>
                    {!m.installed && <Badge className="ml-2">Coming</Badge>}
                    {m.override && (
                      <span className="text-fg-muted block text-sm">
                        Exception: {m.override.reason}
                      </span>
                    )}
                  </span>
                  <span className="flex items-center gap-2">
                    <Badge tone={m.enabled ? 'success' : 'neutral'} dot>
                      {m.enabled ? 'Included' : 'Not included'}
                    </Badge>
                    {canManage && (
                      <Button variant="ghost" size="sm" onClick={() => setEditing(m)}>
                        Change
                      </Button>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      </div>
      {editing && (
        <OverrideDialog business={business} row={editing} onClose={() => setEditing(null)} />
      )}
    </AppShell>
  );
}
