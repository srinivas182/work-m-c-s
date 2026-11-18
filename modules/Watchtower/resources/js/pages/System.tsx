import { Head } from '@inertiajs/react';
import { Badge } from '@/design-system/components/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { PageHeader, StatCard } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { at } from './Incidents';
import { __ } from '@/i18n';

interface Shard {
  name: string;
  status: string;
  businesses: number;
  capacity: number;
  schema_version: number;
  expected_version: number;
  migrated_at: string | null;
}

interface Backup {
  target: string;
  kind: string | null;
  taken_at: string | null;
  size_bytes: number | null;
  location: string | null;
  late: boolean;
  expected: boolean;
}

interface Drill {
  scope: string;
  target: string | null;
  backup_at: string | null;
  failed_at: string;
  restored_at: string;
  restore_minutes: number;
  data_loss_minutes: number | null;
  passed: boolean;
  notes: string | null;
}

interface Move {
  business: string;
  kind: string;
  status: string;
  from: string | null;
  to: string;
  rows: number;
  error: string | null;
  started_at: string;
  clear_after: string | null;
}

interface Props {
  release: string;
  central: { applied: number; pending: string[]; ok: boolean };
  shards: Shard[];
  backups: Backup[];
  backup_max_age_hours: number;
  drills: Drill[];
  targets: { rpo_minutes: number; rto_minutes: number };
  moves: Move[];
}

const size = (b: number | null) => {
  if (b === null) return '—';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let n = b;
  let i = 0;
  while (n >= 1024 && i < units.length - 1) {
    n /= 1024;
    i++;
  }
  return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
};

const duration = (m: number) => (m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${m % 60} min`);

const th = 'py-2 pr-3 font-medium';
const td = 'py-2 pr-3';

/** Admin → System (Sprint 18.4c): migrations per database, backups, restore drills and business moves — is WURK's data safe? */
export default function System({
  release,
  central,
  shards,
  backups,
  backup_max_age_hours,
  drills,
  targets,
  moves,
}: Props) {
  const behind = shards.filter((s) => s.schema_version < s.expected_version);
  const late = backups.filter((b) => b.late);
  const lastDrill = drills[0] ?? null;
  return (
    <AppShell title={__('System')}>
      <Head title={__('System')} />
      <div className="grid max-w-6xl gap-6">
        <PageHeader
          title={__('System')}
          description={`Is WURK’s data safe and up to date? Running ${release}. The commands that change these are in the restore runbook (docs/runbooks/restore.md).`}
        />
        <div className="grid gap-4 sm:grid-cols-4">
          <StatCard label={__('Shards')} value={String(shards.length)} />
          <StatCard
            label={__('Databases behind on migrations')}
            value={String(behind.length + (central.pending.length > 0 ? 1 : 0))}
          />
          <StatCard label={__('Backups overdue')} value={String(late.length)} />
          <StatCard
            label={__('Last restore drill')}
            value={lastDrill ? (lastDrill.passed ? 'Passed' : 'Missed targets') : 'None yet'}
          />
        </div>

        <Card>
          <CardHeader>
            <CardTitle>{__('Databases')}</CardTitle>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <table className="w-full text-sm">
              <caption className="sr-only">{__('Databases and their migrations')}</caption>
              <thead>
                <tr className="text-fg-muted text-left">
                  <th className={th}>{__('Database')}</th>
                  <th className={th}>{__('State')}</th>
                  <th className={`${th} text-right`}>{__('Businesses')}</th>
                  <th className={th}>{__('Migrations')}</th>
                  <th className={th}>{__('Last migrated')}</th>
                </tr>
              </thead>
              <tbody>
                <tr className="border-border border-t">
                  <th scope="row" className={`${td} text-left font-normal`}>
                    {__('Central')}
                  </th>
                  <td className={td}>
                    <Badge tone={central.ok ? 'success' : 'danger'} dot>
                      {central.ok ? 'Answering' : 'Failing'}
                    </Badge>
                  </td>
                  <td className={`${td} text-right tabular-nums`}>—</td>
                  <td className={td}>
                    {central.pending.length === 0 ? (
                      <Badge tone="success">
                        {__('Up to date (')}
                        {central.applied})
                      </Badge>
                    ) : (
                      <Badge tone="warning">
                        {central.pending.length} {__('waiting')}
                      </Badge>
                    )}
                  </td>
                  <td className={td}>—</td>
                </tr>
                {shards.map((s) => (
                  <tr key={s.name} className="border-border border-t">
                    <th scope="row" className={`${td} text-left font-normal`}>
                      {s.name}
                    </th>
                    <td className={td}>{s.status}</td>
                    <td className={`${td} text-right tabular-nums`}>
                      {s.businesses.toLocaleString()} {__('of')} {s.capacity.toLocaleString()}
                    </td>
                    <td className={td}>
                      {s.schema_version >= s.expected_version ? (
                        <Badge tone="success">
                          {__('Up to date (')}
                          {s.schema_version})
                        </Badge>
                      ) : (
                        <Badge tone="warning">
                          {s.expected_version - s.schema_version}{' '}
                          {__('waiting — run shards:migrate')}
                        </Badge>
                      )}
                    </td>
                    <td className={td}>{at(s.migrated_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>
              {__('Backups')}{' '}
              {late.length > 0 && (
                <Badge tone="danger">
                  {late.length} {__('overdue')}
                </Badge>
              )}
            </CardTitle>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            {backups.length === 0 ? (
              <p className="text-fg-muted text-sm">
                {__('No backups reported yet. Each backup job reports in with')}{' '}
                <code>php artisan system:backup-taken &lt;target&gt;</code>
                {__('; list the targets that must have one in')} <code>BACKUP_TARGETS</code>{' '}
                {__('and Watchtower raises a problem when one is more than')} {backup_max_age_hours}{' '}
                {__('hours old.')}
              </p>
            ) : (
              <table className="w-full text-sm">
                <caption className="sr-only">{__('Latest backup of each part of WURK')}</caption>
                <thead>
                  <tr className="text-fg-muted text-left">
                    <th className={th}>{__('What')}</th>
                    <th className={th}>{__('Latest')}</th>
                    <th className={th}>{__('Kind')}</th>
                    <th className={`${th} text-right`}>{__('Size')}</th>
                    <th className={th}>{__('Kept at')}</th>
                  </tr>
                </thead>
                <tbody>
                  {backups.map((b) => (
                    <tr key={b.target} className="border-border border-t">
                      <th scope="row" className={`${td} text-left font-normal`}>
                        {b.target}
                      </th>
                      <td className={td}>
                        {b.late ? (
                          <Badge tone="danger" dot>
                            {b.taken_at ? `Overdue — ${at(b.taken_at)}` : 'Never'}
                          </Badge>
                        ) : (
                          at(b.taken_at)
                        )}
                      </td>
                      <td className={td}>{b.kind ?? '—'}</td>
                      <td className={`${td} text-right tabular-nums`}>{size(b.size_bytes)}</td>
                      <td className={`${td} break-all`}>{b.location ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Restore drills')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3 overflow-x-auto">
            <p className="text-fg-muted text-sm">
              {__('Targets: data lost at most')} {targets.rpo_minutes}{' '}
              {__('minutes; working again within')} {duration(targets.rto_minutes)}
              {__('. Record a drill with')} <code>php artisan system:drill-recorded</code>.
            </p>
            {drills.length > 0 && (
              <table className="w-full text-sm">
                <caption className="sr-only">{__('Latest restore drills')}</caption>
                <thead>
                  <tr className="text-fg-muted text-left">
                    <th className={th}>{__('When')}</th>
                    <th className={th}>{__('What')}</th>
                    <th className={th}>{__('Back in')}</th>
                    <th className={th}>{__('Data lost')}</th>
                    <th className={th}>{__('Result')}</th>
                  </tr>
                </thead>
                <tbody>
                  {drills.map((d) => (
                    <tr key={d.restored_at + d.scope} className="border-border border-t align-top">
                      <td className={td}>{at(d.restored_at)}</td>
                      <td className={td}>
                        {d.scope}
                        {d.target ? ` · ${d.target}` : ''}
                        {d.notes && <p className="text-fg-muted">{d.notes}</p>}
                      </td>
                      <td className={`${td} tabular-nums`}>{duration(d.restore_minutes)}</td>
                      <td className={`${td} tabular-nums`}>
                        {d.data_loss_minutes === null ? '—' : `${d.data_loss_minutes} min`}
                      </td>
                      <td className={td}>
                        <Badge tone={d.passed ? 'success' : 'danger'}>
                          {d.passed ? 'Passed' : 'Missed'}
                        </Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Business moves and restores')}</CardTitle>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            {moves.length === 0 ? (
              <p className="text-fg-muted text-sm">
                {__('None yet.')} <code>tenants:move</code> {__('and')} <code>tenants:restore</code>{' '}
                {__('are recorded here.')}
              </p>
            ) : (
              <table className="w-full text-sm">
                <caption className="sr-only">{__('Recent business moves and restores')}</caption>
                <thead>
                  <tr className="text-fg-muted text-left">
                    <th className={th}>{__('When')}</th>
                    <th className={th}>{__('Business')}</th>
                    <th className={th}>{__('What')}</th>
                    <th className={`${th} text-right`}>{__('Rows')}</th>
                    <th className={th}>{__('Result')}</th>
                  </tr>
                </thead>
                <tbody>
                  {moves.map((m) => (
                    <tr
                      key={m.started_at + m.business}
                      className="border-border border-t align-top"
                    >
                      <td className={td}>{at(m.started_at)}</td>
                      <td className={td}>{m.business}</td>
                      <td className={td}>
                        {m.kind === 'move' ? 'Moved' : 'Restored'}
                        {m.from && m.from !== m.to ? ` from ${m.from}` : ''} {__('to')} {m.to}
                        {m.clear_after && (
                          <p className="text-fg-muted">
                            {__('Old copy kept until')} {at(m.clear_after)}
                          </p>
                        )}
                      </td>
                      <td className={`${td} text-right tabular-nums`}>{m.rows.toLocaleString()}</td>
                      <td className={td}>
                        <Badge
                          tone={
                            m.status === 'failed'
                              ? 'danger'
                              : m.status === 'copying'
                                ? 'warning'
                                : 'success'
                          }
                        >
                          {m.status}
                        </Badge>
                        {m.error && <p className="text-danger-11">{m.error}</p>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
