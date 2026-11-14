import { Head, Link, useForm } from '@inertiajs/react';
import { HeartPulse } from 'lucide-react';
import { useState } from 'react';
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
import { rand } from '@/lib/money';

interface Signal {
  key: string;
  points: number;
  reason: string;
  action: string;
}

interface Row {
  tenant_id: string;
  name: string;
  package: string | null;
  score: number;
  previous: number | null;
  grade: 'healthy' | 'watch' | 'at_risk';
  mrr_cents: number;
  signals: Signal[];
  at_risk_since: string | null;
}

interface Props {
  filters: { grade: string; package: string; sort: string; q: string };
  grades: Record<string, string>;
  sorts: Record<string, string>;
  packages: string[];
  summary: Record<string, { count: number; mrr_cents: number }>;
  computed_at: string | null;
  digest_email: string | null;
  rows: { data: Row[]; total: number; next: string | null; prev: string | null };
}

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const tone = { healthy: 'success', watch: 'warning', at_risk: 'danger' } as const;

/** Admin → Business health (Sprint 16.5): who needs help, why, and what to do — most MRR at risk first. */
export default function Health({
  filters,
  grades,
  sorts,
  packages,
  summary,
  computed_at,
  digest_email,
  rows,
}: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  const [q, setQ] = useState(filters.q);
  const href = (over: Partial<Props['filters']>) => {
    const p = Object.fromEntries(
      Object.entries({ ...filters, ...over }).filter(([, v]) => v !== ''),
    );
    return `${base}?${new URLSearchParams(p as Record<string, string>).toString()}`;
  };
  const digest = useForm({ digest_email: digest_email ?? '' });
  const atRisk = (summary.at_risk?.mrr_cents ?? 0) + (summary.watch?.mrr_cents ?? 0);
  return (
    <AppShell title="Business health">
      <Head title="Business health" />
      <div className="grid max-w-6xl gap-6">
        <PageHeader
          title="Business health"
          description={`Every business scored nightly from what WURK sees — with why, and what to do. ${computed_at ? `Last worked out ${when.format(new Date(computed_at))}.` : 'Not worked out yet — it runs nightly.'}`}
          actions={
            <Button asChild variant="secondary">
              <a href={href({}).replace(base, `${base}/export`)}>Export CSV</a>
            </Button>
          }
        />
        <div className="grid gap-4 sm:grid-cols-4">
          {Object.entries(grades).map(([g, label]) => (
            <StatCard
              key={g}
              label={label}
              value={String(summary[g]?.count ?? 0)}
              hint={`${rand(summary[g]?.mrr_cents ?? 0)} MRR`}
            />
          ))}
          <StatCard label="MRR at risk (watch + at risk)" value={rand(atRisk)} />
        </div>
        <nav aria-label="Filter" className="flex flex-wrap items-end gap-2">
          {[['', 'Needs attention'], ...Object.entries(grades)].map(([g, label]) => (
            <Button
              key={g || 'attention'}
              asChild
              size="sm"
              variant={filters.grade === g ? 'primary' : 'secondary'}
            >
              <Link
                href={href({ grade: g ?? '' })}
                aria-current={filters.grade === g ? 'page' : undefined}
              >
                {label}
              </Link>
            </Button>
          ))}
          <span className="text-fg-muted self-center px-1 text-sm">Sort:</span>
          {Object.entries(sorts).map(([s, label]) => (
            <Button key={s} asChild size="sm" variant={filters.sort === s ? 'soft' : 'ghost'}>
              <Link href={href({ sort: s })} aria-current={filters.sort === s ? 'page' : undefined}>
                {label}
              </Link>
            </Button>
          ))}
        </nav>
        <form
          role="search"
          className="flex flex-wrap items-end gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            window.location.href = href({ q });
          }}
        >
          <Field label="Business name">
            <Input value={q} onChange={(e) => setQ(e.target.value)} />
          </Field>
          {packages.length > 0 && (
            <nav aria-label="Package" className="flex flex-wrap gap-1">
              {['', ...packages].map((p) => (
                <Button
                  key={p || 'all'}
                  asChild
                  size="sm"
                  variant={filters.package === p ? 'soft' : 'ghost'}
                >
                  <Link href={href({ package: p })}>{p || 'All packages'}</Link>
                </Button>
              ))}
            </nav>
          )}
          <Button type="submit" variant="secondary">
            Search
          </Button>
        </form>
        {rows.data.length === 0 ? (
          <EmptyState
            icon={HeartPulse}
            title="Nothing to show"
            description="No businesses match — or scores haven’t been worked out yet."
          />
        ) : (
          <ul aria-label="Businesses" className="grid gap-3">
            {rows.data.map((r) => (
              <li key={r.tenant_id}>
                <Card>
                  <CardContent className="grid gap-2 pt-5 text-sm">
                    <div className="flex flex-wrap items-center gap-3">
                      <strong className="text-base">{r.name}</strong>
                      <Badge tone={tone[r.grade]}>
                        {grades[r.grade]} · {r.score}/100
                      </Badge>
                      {r.previous !== null && r.previous !== r.score && (
                        <span
                          className={r.score < r.previous ? 'text-danger-11' : 'text-success-11'}
                        >
                          {r.score < r.previous ? '▼' : '▲'} from {r.previous}
                        </span>
                      )}
                      <span className="text-fg-muted">
                        {r.package ?? 'No package'} · {rand(r.mrr_cents)}/month
                      </span>
                    </div>
                    {r.signals.length === 0 ? (
                      <p className="text-fg-muted">Nothing worrying.</p>
                    ) : (
                      <ul className="grid gap-1">
                        {r.signals.map((s) => (
                          <li key={s.key}>
                            <span>{s.reason}</span>{' '}
                            <span className="text-fg-muted">→ {s.action}</span>
                          </li>
                        ))}
                      </ul>
                    )}
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
        <Card>
          <CardHeader>
            <CardTitle>Weekly digest</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              aria-label="Weekly digest"
              className="flex flex-wrap items-end gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                digest.put(`${base}/settings`, { preserveScroll: true });
              }}
            >
              <Field
                label="Send Monday’s digest to"
                error={digest.errors.digest_email}
                hint="Empty: WURK’s support inbox email."
              >
                <Input
                  type="email"
                  value={digest.data.digest_email}
                  onChange={(e) => digest.setData('digest_email', e.target.value)}
                />
              </Field>
              <Button type="submit" variant="secondary" loading={digest.processing}>
                Save
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
