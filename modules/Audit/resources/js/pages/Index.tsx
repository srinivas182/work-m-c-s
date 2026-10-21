import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, Download, Search, ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import {
  Badge,
  Button,
  EmptyState,
  Field,
  Input,
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

interface AuditRow {
  id: string;
  seq: number;
  at: string;
  actor: string;
  action: string;
  subject: string | null;
  details: Record<string, unknown> | null;
  ip: string | null;
}

interface Props {
  scope: string;
  filters: { action?: string; actor?: string; from?: string; to?: string };
  events: { data: AuditRow[]; total: number; next: string | null; prev: string | null };
}

/** Plain-language names for audit actions; unknown actions fall back to the raw key. */
const LABELS: Record<string, string> = {
  'identity.signed_in': 'Signed in',
  'identity.sign_in_failed': 'Sign-in failed',
  'identity.code_requested': 'Sign-in code requested',
  'identity.mfa_enabled': 'Authenticator turned on',
  'identity.mfa_disabled': 'Authenticator turned off',
  'identity.password_changed': 'Password changed',
  'identity.sessions_revoked': 'Signed out other devices',
  'identity.signed_out': 'Signed out',
  'identity.staff_granted': 'WURK staff access granted',
  'identity.membership_granted': 'Added to a business',
  'tenant.created': 'Business created',
  'tenant.status_changed': 'Business status changed',
  'role.assigned': 'Role given',
  'role.revoked': 'Role removed',
  'role.created': 'Custom role created',
  'role.updated': 'Custom role changed',
  'role.deleted': 'Custom role deleted',
  'member.branches_changed': 'Branch access changed',
  'platform_role.granted': 'Staff role given',
  'platform_role.revoked': 'Staff role removed',
  'platform_role.created': 'Staff role created',
  'platform_role.deleted': 'Staff role deleted',
};

const GROUPS = [
  { value: 'all', label: 'All activity' },
  { value: 'identity.', label: 'Sign-in & security' },
  { value: 'role.', label: 'Roles & team' },
  { value: 'member.', label: 'Branch access' },
  { value: 'tenant.', label: 'Businesses' },
  { value: 'platform_role.', label: 'WURK staff roles' },
];

const tone = (action: string) =>
  action.endsWith('failed') ||
  action.endsWith('revoked') ||
  action.endsWith('deleted') ||
  action.endsWith('disabled')
    ? 'warning'
    : action.startsWith('identity.signed') || action.endsWith('requested')
      ? 'neutral'
      : 'brand';

const formatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'medium' });

function Details({ details }: { details: Record<string, unknown> | null }) {
  const [open, setOpen] = useState(false);
  if (!details || Object.keys(details).length === 0)
    return <span className="text-fg-muted">—</span>;
  return (
    <div>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="text-accent-fg inline-flex cursor-pointer items-center gap-1 text-sm font-medium hover:underline"
      >
        {open ? 'Hide' : 'Show'} details{' '}
        <ChevronDown
          className={`size-4 transition-transform ${open ? 'rotate-180' : ''}`}
          aria-hidden
        />
      </button>
      {open && (
        <pre className="bg-sunken mt-2 max-w-md overflow-x-auto rounded-lg p-3 font-mono text-xs whitespace-pre-wrap">
          {JSON.stringify(details, null, 2)}
        </pre>
      )}
    </div>
  );
}

export default function Index({ scope, filters, events }: Props) {
  const [form, setForm] = useState({
    action: filters.action ?? 'all',
    actor: filters.actor ?? '',
    from: filters.from ?? '',
    to: filters.to ?? '',
  });

  const apply = (e: FormEvent) => {
    e.preventDefault();
    const query = Object.fromEntries(
      Object.entries({ ...form, action: form.action === 'all' ? '' : form.action }).filter(
        ([, v]) => v !== '',
      ),
    );
    router.get(window.location.pathname, query, { preserveState: true, preserveScroll: true });
  };

  const exportUrl = `${window.location.pathname}?${new URLSearchParams({ ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), format: 'csv' }).toString()}`;

  return (
    <AppShell title="Audit log">
      <Head title="Audit log" />
      <div className="grid gap-8">
        <PageHeader
          eyebrow={scope}
          title="Audit log"
          description={
            scope === 'WURK platform'
              ? 'Every sign-in, business change and staff access change. Entries are chained together, so nothing can be edited or removed unnoticed.'
              : 'Every change to your team, roles and settings. Entries are chained together, so nothing can be edited or removed unnoticed.'
          }
          actions={
            <Button variant="secondary" asChild>
              <a href={exportUrl}>
                <Download /> Export CSV
              </a>
            </Button>
          }
        />

        <form
          onSubmit={apply}
          className="border-border bg-surface grid grid-cols-1 items-end gap-4 rounded-2xl border p-5 shadow-xs sm:grid-cols-2 lg:grid-cols-[1.2fr_1.2fr_1fr_1fr_auto]"
        >
          <Field label="Activity">
            {(control) => (
              <Select
                value={form.action}
                onValueChange={(v) => setForm((f) => ({ ...f, action: v }))}
              >
                <SelectTrigger {...control}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {GROUPS.map((g) => (
                    <SelectItem key={g.value} value={g.value}>
                      {g.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </Field>
          <Field label="Person">
            <Input
              value={form.actor}
              onChange={(e) => setForm((f) => ({ ...f, actor: e.target.value }))}
              placeholder="Name"
            />
          </Field>
          <Field label="From">
            <Input
              type="date"
              value={form.from}
              onChange={(e) => setForm((f) => ({ ...f, from: e.target.value }))}
            />
          </Field>
          <Field label="To">
            <Input
              type="date"
              value={form.to}
              onChange={(e) => setForm((f) => ({ ...f, to: e.target.value }))}
            />
          </Field>
          <Button type="submit">
            <Search /> Filter
          </Button>
        </form>

        {events.data.length === 0 ? (
          <EmptyState
            icon={ShieldCheck}
            title="No activity found"
            description="Try a wider date range or a different activity type."
          />
        ) : (
          <div className="grid gap-4">
            <Table label="Audit events">
              <TableHeader>
                <TableRow>
                  <TableHead>When</TableHead>
                  <TableHead>Who</TableHead>
                  <TableHead>What</TableHead>
                  <TableHead>Details</TableHead>
                  <TableHead>IP address</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {events.data.map((e) => (
                  <TableRow key={e.id} className="align-top">
                    <TableCell className="tabular-nums">
                      <span className="block">{formatter.format(new Date(e.at))}</span>
                      <span className="text-fg-muted text-xs">#{e.seq}</span>
                    </TableCell>
                    <TableCell className="font-medium">{e.actor}</TableCell>
                    <TableCell>
                      <Badge tone={tone(e.action)}>{LABELS[e.action] ?? e.action}</Badge>
                    </TableCell>
                    <TableCell className="whitespace-normal">
                      <Details details={e.details} />
                    </TableCell>
                    <TableCell className="text-fg-muted font-mono text-xs">{e.ip ?? '—'}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <div className="text-fg-muted flex items-center justify-between text-sm">
              <span>
                {events.total.toLocaleString()} {events.total === 1 ? 'event' : 'events'}
              </span>
              <div className="flex gap-2">
                {events.prev && (
                  <Button variant="secondary" size="sm" asChild>
                    <Link href={events.prev} preserveScroll>
                      Newer
                    </Link>
                  </Button>
                )}
                {events.next && (
                  <Button variant="secondary" size="sm" asChild>
                    <Link href={events.next} preserveScroll>
                      Older
                    </Link>
                  </Button>
                )}
              </div>
            </div>
          </div>
        )}
      </div>
    </AppShell>
  );
}
