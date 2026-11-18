import { Head, Link, router, useForm } from '@inertiajs/react';
import { Radar } from 'lucide-react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  EmptyState,
  Field,
  Input,
  PageHeader,
  StatCard,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

export interface IncidentRow {
  id: string;
  reference: string;
  kind: string;
  severity: 'critical' | 'high' | 'normal';
  status: string;
  title: string;
  location: string | null;
  surface: string | null;
  occurrences: number;
  last_hour: number;
  businesses: number;
  last_release: string | null;
  ongoing: boolean;
  regressed: boolean;
  first_seen_at: string;
  last_seen_at: string;
}

interface Check {
  name: string;
  ok: boolean;
  message: string | null;
  checked_at: string;
  failing_since: string | null;
}

interface Task {
  task: string;
  expression: string;
  last_started_at: string | null;
  last_status: string | null;
  last_runtime_ms: number | null;
}

interface Uptime {
  monitor: string;
  uptime_pct: number;
  down_minutes: number;
  down_now: boolean;
}

interface Slow {
  route: string;
  samples: number;
  over_budget_pct: number;
  over_2s: number;
  max_ms: number;
}

interface Props {
  uptime: Uptime[];
  slow: Slow[];
  ai: { enabled: boolean; today: number; cap: number };
  filters: { view: string; severity: string; kind: string };
  views: Record<string, string>;
  severities: Record<string, string>;
  kinds: Record<string, string>;
  release: string;
  summary: { critical: number; high: number; normal: number; today: number };
  checks: Check[];
  tasks: Task[];
  scheduler: string | null;
  recipients: string[];
  can_manage: boolean;
  uptime_hook?: { url: string | null; source: 'settings' | 'server' | 'none' } | null;
  rows: { data: IncidentRow[]; total: number; next: string | null; prev: string | null };
}

export const severityTone = { critical: 'danger', high: 'warning', normal: 'neutral' } as const;
const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
/** Server times are stored without a zone, in UTC. */
export const at = (s: string | null) =>
  s ? when.format(new Date(/[zZ+]/.test(s.slice(10)) ? s : `${s.replace(' ', 'T')}Z`)) : '—';

/** Admin → Watchtower (Sprint 18.0): every problem on the platform, worst first, and the state of each system. */
export default function Incidents({
  uptime,
  slow,
  ai,
  filters,
  views,
  severities,
  kinds,
  release,
  summary,
  checks,
  tasks,
  scheduler,
  recipients,
  can_manage,
  uptime_hook = null,
  rows,
}: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  const href = (over: Partial<Props['filters']>) => {
    const p = Object.fromEntries(
      Object.entries({ ...filters, ...over }).filter(
        ([k, v]) => v !== '' && !(k === 'view' && v === 'open'),
      ),
    );
    const q = new URLSearchParams(p as Record<string, string>).toString();
    return q ? `${base}?${q}` : base;
  };
  const settings = useForm({ recipients: recipients.join(', ') });
  const failing = checks.filter((c) => !c.ok);
  return (
    <AppShell title="Watchtower">
      <Head title="Watchtower" />
      <div className="grid max-w-6xl gap-6">
        <PageHeader
          title="Watchtower"
          description={`Everything that goes wrong on WURK — grouped into problems, worst first. Running ${release}. Scheduler ${scheduler ? `last checked in ${at(scheduler)}` : 'hasn’t checked in yet'}.`}
        />
        <div className="grid gap-4 sm:grid-cols-4">
          <StatCard label="Critical — open" value={String(summary.critical)} />
          <StatCard label="High — open" value={String(summary.high)} />
          <StatCard label="Normal — open" value={String(summary.normal)} />
          <StatCard label="New today" value={String(summary.today)} />
        </div>

        <Card>
          <CardHeader>
            <CardTitle>
              Systems{' '}
              {checks.length > 0 && (
                <Badge tone={failing.length === 0 ? 'success' : 'danger'}>
                  {failing.length === 0
                    ? `all ${checks.length} passing`
                    : `${failing.length} failing`}
                </Badge>
              )}
            </CardTitle>
          </CardHeader>
          <CardContent>
            {checks.length === 0 ? (
              <p className="text-fg-muted text-sm">
                Not checked yet — checks run every minute once the scheduler is running.
              </p>
            ) : (
              <ul aria-label="System checks" className="grid gap-1 text-sm sm:grid-cols-2">
                {checks.map((c) => (
                  <li key={c.name} className="flex flex-wrap items-center gap-2">
                    <Badge tone={c.ok ? 'success' : 'danger'} dot>
                      {c.ok ? 'OK' : 'Failing'}
                    </Badge>
                    <span>{c.name}</span>
                    {!c.ok && (
                      <span className="text-danger-11">
                        {c.message} (since {at(c.failing_since)})
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <nav aria-label="Show" className="flex flex-wrap items-center gap-2">
          {Object.entries(views).map(([v, label]) => (
            <Button
              key={v}
              asChild
              size="sm"
              variant={filters.view === v ? 'primary' : 'secondary'}
            >
              <Link href={href({ view: v })} aria-current={filters.view === v ? 'page' : undefined}>
                {label}
              </Link>
            </Button>
          ))}
          <span className="text-fg-muted px-1 text-sm">Severity:</span>
          {[['', 'Any'], ...Object.entries(severities)].map(([s, label]) => (
            <Button
              key={s || 'any'}
              asChild
              size="sm"
              variant={filters.severity === s ? 'soft' : 'ghost'}
            >
              <Link href={href({ severity: s ?? '' })}>{label}</Link>
            </Button>
          ))}
          <span className="text-fg-muted px-1 text-sm">Kind:</span>
          {[['', 'Any'], ...Object.entries(kinds)].map(([k, label]) => (
            <Button
              key={k || 'any'}
              asChild
              size="sm"
              variant={filters.kind === k ? 'soft' : 'ghost'}
            >
              <Link href={href({ kind: k ?? '' })}>{label}</Link>
            </Button>
          ))}
        </nav>

        {rows.data.length === 0 ? (
          <EmptyState
            icon={Radar}
            title={filters.view === 'open' ? 'Nothing wrong right now' : 'Nothing here'}
            description="Problems appear here as soon as they happen; critical and high ones are emailed straight away."
          />
        ) : (
          <ul aria-label="Problems" className="grid gap-3">
            {rows.data.map((r) => (
              <li key={r.id}>
                <Card>
                  <CardContent className="grid gap-1 pt-5 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                      <Badge tone={severityTone[r.severity]}>{severities[r.severity]}</Badge>
                      <Badge>{kinds[r.kind] ?? r.kind}</Badge>
                      {r.regressed && <Badge tone="danger">Came back after a fix</Badge>}
                      {r.ongoing && <Badge tone="danger">Still failing</Badge>}
                      <Link
                        href={`${base}/${r.id}`}
                        className="font-medium underline-offset-2 hover:underline"
                      >
                        {r.title}
                      </Link>
                    </div>
                    <p className="text-fg-muted">
                      {r.reference} · {r.occurrences}× ({r.last_hour} in the last hour)
                      {r.businesses > 0 &&
                        ` · ${r.businesses} business${r.businesses === 1 ? '' : 'es'}`}{' '}
                      · last {at(r.last_seen_at)} in {r.last_release ?? '—'}
                      {r.location && ` · ${r.location}`}
                    </p>
                  </CardContent>
                </Card>
              </li>
            ))}
          </ul>
        )}
        {(rows.prev || rows.next) && (
          <nav aria-label="Pages" className="flex gap-2">
            {rows.prev && (
              <Button asChild variant="secondary" size="sm">
                <Link href={rows.prev}>Previous</Link>
              </Button>
            )}
            {rows.next && (
              <Button asChild variant="secondary" size="sm">
                <Link href={rows.next}>Next</Link>
              </Button>
            )}
          </nav>
        )}

        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>Uptime (30 days, seen from outside)</CardTitle>
            </CardHeader>
            <CardContent>
              {uptime.length === 0 ? (
                <p className="text-fg-muted text-sm">
                  No outages reported by the uptime checker — or it isn’t connected yet (Sprint 17).
                </p>
              ) : (
                <ul aria-label="Uptime" className="grid gap-1 text-sm">
                  {uptime.map((u) => (
                    <li key={u.monitor} className="flex flex-wrap items-center gap-2">
                      <Badge
                        tone={u.down_now ? 'danger' : u.uptime_pct >= 99.9 ? 'success' : 'warning'}
                        dot
                      >
                        {u.down_now ? 'Down now' : `${u.uptime_pct}%`}
                      </Badge>
                      <span>{u.monitor}</span>
                      <span className="text-fg-muted">{u.down_minutes} min down</span>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>Slow pages (last 24 hours)</CardTitle>
            </CardHeader>
            <CardContent>
              {slow.length === 0 ? (
                <p className="text-fg-muted text-sm">
                  Every page within target: 95% of requests answered in 300 ms.
                </p>
              ) : (
                <ul aria-label="Slow pages" className="grid gap-1 text-sm">
                  {slow.map((r) => (
                    <li key={r.route}>
                      <code>{r.route}</code>{' '}
                      <span className="text-fg-muted">
                        {r.over_budget_pct}% over 300 ms · {r.over_2s} over 2 s · slowest{' '}
                        {r.max_ms.toLocaleString()} ms
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </div>
        <p className="text-fg-muted text-sm">
          {ai.enabled
            ? `The AI has explained ${ai.today} of up to ${ai.cap} problems today.`
            : 'The AI explanation is switched off (WATCHTOWER_AI).'}
        </p>

        <Card>
          <CardHeader>
            <CardTitle>Scheduled tasks</CardTitle>
          </CardHeader>
          <CardContent>
            {tasks.length === 0 ? (
              <p className="text-fg-muted text-sm">No scheduled task has run yet.</p>
            ) : (
              <ul aria-label="Scheduled tasks" className="grid gap-1 text-sm">
                {tasks.map((t) => (
                  <li key={t.task} className="flex flex-wrap items-center gap-2">
                    <Badge
                      tone={
                        t.last_status === 'failed'
                          ? 'danger'
                          : t.last_status === 'ok'
                            ? 'success'
                            : 'info'
                      }
                      dot
                    >
                      {t.last_status ?? '—'}
                    </Badge>
                    <code>{t.task}</code>
                    <span className="text-fg-muted">
                      ({t.expression}) · last started {at(t.last_started_at)}
                      {t.last_runtime_ms !== null &&
                        ` · took ${(t.last_runtime_ms / 1000).toFixed(1)} s`}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Who gets problem emails</CardTitle>
          </CardHeader>
          <CardContent>
            {can_manage ? (
              <form
                aria-label="Problem emails"
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  settings.transform((d) => ({
                    recipients: d.recipients
                      .split(',')
                      .map((s) => s.trim())
                      .filter(Boolean),
                  }));
                  settings.put(`${base}/settings`, { preserveScroll: true });
                }}
              >
                <Field
                  label="Email addresses (comma-separated, up to 5)"
                  hint="Critical and high problems straight away; a daily report at 08:00 SAST."
                  error={
                    settings.errors.recipients ??
                    Object.entries(settings.errors).find(([k]) => k.startsWith('recipients.'))?.[1]
                  }
                >
                  <Input
                    className="min-w-80"
                    value={settings.data.recipients}
                    onChange={(e) => settings.setData('recipients', e.target.value)}
                  />
                </Field>
                <Button type="submit" disabled={settings.processing}>
                  Save
                </Button>
              </form>
            ) : (
              <p className="text-sm">{recipients.join(', ') || 'No one.'}</p>
            )}
          </CardContent>
        </Card>

        {uptime_hook && (
          <Card>
            <CardHeader>
              <CardTitle>Uptime checker</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
              <p>
                Your uptime checker (Better Stack or UptimeRobot) tells WURK about outages by
                sending to this address. Keep it secret: anyone with it can report an outage.
              </p>
              {uptime_hook.url ? (
                <p className="bg-muted rounded-md p-2 font-mono break-all">{uptime_hook.url}</p>
              ) : (
                <p>No address yet: make one, then paste it into the uptime checker.</p>
              )}
              {uptime_hook.source === 'server' && (
                <p className="text-fg-muted">
                  This address comes from the server’s settings. Making a new one here replaces it.
                </p>
              )}
              <Button
                type="button"
                variant="secondary"
                className="justify-self-start"
                onClick={() => {
                  if (
                    uptime_hook.url === null ||
                    window.confirm('The current address stops working at once. Make a new one?')
                  ) {
                    router.post(`${base}/uptime-token`, {}, { preserveScroll: true });
                  }
                }}
              >
                {uptime_hook.url ? 'Make a new address' : 'Make an address'}
              </Button>
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
