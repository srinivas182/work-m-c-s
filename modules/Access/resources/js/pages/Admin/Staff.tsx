import { Head, router, useForm } from '@inertiajs/react';
import { MailPlus, MoreHorizontal, ShieldCheck, ShieldOff, UserCog } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import {
  Avatar,
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Checkbox,
  Dialog,
  DialogContent,
  DialogFooter,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  Field,
  Input,
  Label,
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Person {
  id: string;
  name: string;
  email: string | null;
  active: boolean;
  mfa: boolean;
  last_login: string | null;
  roles: string[];
}

interface Props {
  staff: Person[];
  pending: { id: string; email: string; role: string; expires_at: string }[];
  roles: { key: string; name: string }[];
}

function InviteCard({ roles }: Pick<Props, 'roles'>) {
  const form = useForm({ email: '', first_name: '', role: 'support' });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post('/staff/invitations', { preserveScroll: true, onSuccess: () => form.reset() });
  };
  return (
    <Card>
      <CardHeader>
        <CardTitle>Invite a colleague</CardTitle>
        <CardDescription>
          They’ll set up an authenticator app the first time they sign in.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form
          onSubmit={submit}
          className="grid grid-cols-1 items-end gap-4 sm:grid-cols-[2fr_1.2fr_1.2fr_auto]"
        >
          <Field label="Email" error={form.errors.email} required>
            <Input
              type="email"
              value={form.data.email}
              onChange={(e) => form.setData('email', e.target.value)}
            />
          </Field>
          <Field label="First name">
            <Input
              value={form.data.first_name}
              onChange={(e) => form.setData('first_name', e.target.value)}
            />
          </Field>
          <Field label="Role" error={form.errors.role}>
            {(control) => (
              <Select value={form.data.role} onValueChange={(v) => form.setData('role', v)}>
                <SelectTrigger {...control}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {roles.map((r) => (
                    <SelectItem key={r.key} value={r.key}>
                      {r.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </Field>
          <Button type="submit" loading={form.processing}>
            <MailPlus /> Send invitation
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function RolesDialog({
  person,
  roles,
  onClose,
}: {
  person: Person;
  roles: Props['roles'];
  onClose: () => void;
}) {
  const form = useForm({ roles: person.roles });
  const toggle = (key: string, on: boolean) =>
    form.setData(
      'roles',
      on ? [...form.data.roles, key] : form.data.roles.filter((r) => r !== key),
    );
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent
        title={`Roles for ${person.name}`}
        description="Permissions come from roles. Changes take effect on their next click."
      >
        <form
          onSubmit={(e) => {
            e.preventDefault();
            form.put(`/staff/${person.id}/roles`, { preserveScroll: true, onSuccess: onClose });
          }}
        >
          <div className="grid gap-3">
            {roles.map((r) => (
              <div key={r.key} className="flex items-center gap-3">
                <Checkbox
                  id={`role-${r.key}`}
                  checked={form.data.roles.includes(r.key)}
                  onCheckedChange={(v) => toggle(r.key, v === true)}
                />
                <Label htmlFor={`role-${r.key}`}>{r.name}</Label>
              </div>
            ))}
          </div>
          {form.errors.roles && (
            <p className="text-danger-11 mt-3 text-sm" role="alert">
              {form.errors.roles}
            </p>
          )}
          <DialogFooter>
            <Button type="submit" loading={form.processing} disabled={form.data.roles.length === 0}>
              Save roles
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export default function Staff({ staff, pending, roles }: Props) {
  const [editing, setEditing] = useState<Person | null>(null);
  const name = (key: string) => roles.find((r) => r.key === key)?.name ?? key;

  return (
    <AppShell title="WURK staff">
      <Head title="WURK staff" />
      <div className="grid gap-8">
        <PageHeader
          title="WURK staff"
          description="People who can sign in to the WURK admin. Everyone uses an authenticator app."
        />
        <InviteCard roles={roles} />
        <Table label="WURK staff">
          <TableHeader>
            <TableRow>
              <TableHead>Person</TableHead>
              <TableHead>Roles</TableHead>
              <TableHead>Security</TableHead>
              <TableHead>Last sign-in</TableHead>
              <TableHead className="w-12">
                <span className="sr-only">Actions</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {staff.map((p) => (
              <TableRow key={p.id} className={p.active ? undefined : 'opacity-60'}>
                <TableCell>
                  <div className="flex items-center gap-3">
                    <Avatar name={p.name} size="sm" />
                    <span>
                      <span className="block font-medium">{p.name}</span>
                      <span className="text-fg-muted block text-sm">{p.email}</span>
                    </span>
                  </div>
                </TableCell>
                <TableCell>
                  <span className="flex flex-wrap gap-1.5">
                    {p.roles.map((r) => (
                      <Badge key={r} tone="brand">
                        {name(r)}
                      </Badge>
                    ))}
                    {!p.active && <Badge tone="danger">Deactivated</Badge>}
                  </span>
                </TableCell>
                <TableCell>
                  {p.mfa ? (
                    <Badge tone="success">
                      <ShieldCheck /> Authenticator on
                    </Badge>
                  ) : (
                    <Badge tone="warning">
                      <ShieldOff /> Set up on first sign-in
                    </Badge>
                  )}
                </TableCell>
                <TableCell className="text-fg-muted">
                  {p.last_login ? new Date(p.last_login).toLocaleString() : 'Never'}
                </TableCell>
                <TableCell>
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${p.name}`}>
                        <MoreHorizontal />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem onSelect={() => setEditing(p)}>
                        <UserCog /> Change roles
                      </DropdownMenuItem>
                      {p.active ? (
                        <DropdownMenuItem
                          destructive
                          onSelect={() =>
                            router.post(`/staff/${p.id}/deactivate`, {}, { preserveScroll: true })
                          }
                        >
                          <ShieldOff /> Deactivate
                        </DropdownMenuItem>
                      ) : (
                        <DropdownMenuItem
                          onSelect={() =>
                            router.post(`/staff/${p.id}/reactivate`, {}, { preserveScroll: true })
                          }
                        >
                          <ShieldCheck /> Reactivate
                        </DropdownMenuItem>
                      )}
                    </DropdownMenuContent>
                  </DropdownMenu>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
        {pending.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Waiting to accept</CardTitle>
            </CardHeader>
            <CardContent>
              <ul className="divide-border divide-y">
                {pending.map((i) => (
                  <li key={i.id} className="flex items-center justify-between gap-3 py-3">
                    <span>
                      <span className="font-medium">{i.email}</span>{' '}
                      <span className="text-fg-muted">
                        · {name(i.role)} · expires {new Date(i.expires_at).toLocaleDateString()}
                      </span>
                    </span>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() =>
                        router.delete(`/staff/invitations/${i.id}`, { preserveScroll: true })
                      }
                    >
                      Cancel
                    </Button>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}
      </div>
      {editing && <RolesDialog person={editing} roles={roles} onClose={() => setEditing(null)} />}
    </AppShell>
  );
}
