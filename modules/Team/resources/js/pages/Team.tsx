import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Checkbox,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Member {
  id: string;
  identity_id: string;
  name: string;
  role: string;
  all_branches: boolean;
  branches: string[];
}
interface Role {
  key: string;
  name: string;
  branch_restricted: boolean;
}

const selectClass = 'h-9 rounded-md border border-border bg-bg px-2 text-sm';

function MemberRow({
  m,
  roles,
  branches,
  base,
  me,
  canManage,
  onError,
}: {
  m: Member;
  roles: Role[];
  branches: { id: string; name: string }[];
  base: string;
  me: string;
  canManage: boolean;
  onError: (e: string | null) => void;
}) {
  const restricted = roles.find((r) => r.key === m.role)?.branch_restricted ?? false;
  const [chosen, setChosen] = useState<string[]>(m.branches);
  const [all, setAll] = useState(m.all_branches && !restricted);
  const self = m.identity_id === me;
  const done = {
    preserveScroll: true,
    onError: (e: Record<string, string>) => onError(Object.values(e)[0] ?? null),
    onSuccess: () => onError(null),
  };
  const roleName = roles.find((r) => r.key === m.role)?.name ?? m.role;
  return (
    <li className="border-border grid gap-3 rounded-lg border p-3 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span>
          <strong>{m.name}</strong> {self && <Badge tone="neutral">You</Badge>}
        </span>
        {canManage && !self ? (
          <label className="flex items-center gap-2">
            Role
            <select
              className={selectClass}
              value={m.role}
              onChange={(e) => router.put(`${base}/${m.id}/role`, { role: e.target.value }, done)}
            >
              {roles.map((r) => (
                <option key={r.key} value={r.key}>
                  {r.name}
                </option>
              ))}
            </select>
          </label>
        ) : (
          <span>{roleName}</span>
        )}
      </div>
      {branches.length > 1 && m.role !== 'owner' && (
        <fieldset className="grid gap-2" disabled={!canManage || self}>
          <legend className="font-medium">Branches</legend>
          {!restricted && (
            <label className="flex items-center gap-2">
              <Checkbox checked={all} onCheckedChange={(on) => setAll(on === true)} />
              All branches
            </label>
          )}
          {!all &&
            branches.map((b) => (
              <label key={b.id} className="flex items-center gap-2">
                <Checkbox
                  checked={chosen.includes(b.id)}
                  onCheckedChange={(on) =>
                    setChosen(on === true ? [...chosen, b.id] : chosen.filter((x) => x !== b.id))
                  }
                />
                {b.name}
              </label>
            ))}
          {canManage && !self && (
            <Button
              variant="secondary"
              size="sm"
              className="justify-self-start"
              aria-label={`Save branches for ${m.name}`}
              onClick={() =>
                router.put(`${base}/${m.id}/branches`, { all, branches: chosen }, done)
              }
            >
              Save branches
            </Button>
          )}
        </fieldset>
      )}
      {canManage && !self && (
        <Button
          variant="ghost"
          size="sm"
          className="justify-self-start"
          aria-label={`Remove ${m.name}`}
          onClick={() => {
            if (window.confirm(`Remove ${m.name} from the team? Their past work stays on record.`))
              router.delete(`${base}/${m.id}`, done);
          }}
        >
          Remove from team
        </Button>
      )}
    </li>
  );
}

export default function TeamPage({
  members,
  roles,
  branches,
  pending,
  me,
  canManage,
}: {
  members: Member[];
  roles: Role[];
  branches: { id: string; name: string }[];
  pending: { id: string; email: string; role: string; expires_at: string }[];
  me: string;
  canManage: boolean;
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  const [error, setError] = useState<string | null>(null);
  const invite = useForm({ email: '', role: 'staff', first_name: '', last_name: '' });
  return (
    <AppShell title="Team">
      <Head title="Team" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Team"
          description="Who works in your business, what they can do, and — with more than one branch — where."
        />
        {error && (
          <p role="alert" className="text-danger text-sm">
            {error}
          </p>
        )}
        <ul className="grid gap-3" aria-label="Team members">
          {members.map((m) => (
            <MemberRow
              key={m.id}
              m={m}
              roles={roles}
              branches={branches}
              base={base}
              me={me}
              canManage={canManage}
              onError={setError}
            />
          ))}
        </ul>
        {pending.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Invitations waiting</CardTitle>
            </CardHeader>
            <CardContent>
              <ul className="grid gap-2 text-sm" aria-label="Invitations waiting">
                {pending.map((p) => (
                  <li key={p.id} className="flex flex-wrap items-center justify-between gap-2">
                    <span>
                      {p.email} · {roles.find((r) => r.key === p.role)?.name ?? p.role}
                    </span>
                    {canManage && (
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-label={`Cancel the invitation to ${p.email}`}
                        onClick={() =>
                          router.delete(`${base}/invitations/${p.id}`, { preserveScroll: true })
                        }
                      >
                        Cancel
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}
        {canManage && (
          <Card>
            <CardHeader>
              <CardTitle>Invite someone</CardTitle>
            </CardHeader>
            <CardContent>
              <form
                aria-label="Invite someone"
                className="grid gap-3 sm:grid-cols-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  invite.post(`${base}/invitations`, {
                    preserveScroll: true,
                    onSuccess: () => invite.reset(),
                  });
                }}
              >
                <Field label="Email" error={invite.errors.email}>
                  <Input
                    type="email"
                    value={invite.data.email}
                    onChange={(e) => invite.setData('email', e.target.value)}
                  />
                </Field>
                <Field label="Role" error={invite.errors.role}>
                  <select
                    className={`${selectClass} h-10 w-full`}
                    value={invite.data.role}
                    onChange={(e) => invite.setData('role', e.target.value)}
                  >
                    {roles.map((r) => (
                      <option key={r.key} value={r.key}>
                        {r.name}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="First name (optional)">
                  <Input
                    value={invite.data.first_name}
                    onChange={(e) => invite.setData('first_name', e.target.value)}
                  />
                </Field>
                <Field label="Last name (optional)">
                  <Input
                    value={invite.data.last_name}
                    onChange={(e) => invite.setData('last_name', e.target.value)}
                  />
                </Field>
                <Button type="submit" className="justify-self-start" loading={invite.processing}>
                  Send invitation
                </Button>
              </form>
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
