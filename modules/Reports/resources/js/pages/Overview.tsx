import { Head, router } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle, EmptyState, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface Metric {
  label: string;
  value: number;
  previous: number;
  format: 'money' | 'count' | 'percent';
}
interface Table {
  columns: string[];
  data: string[][];
}
interface Section {
  key: string;
  title: string;
  metrics: Metric[];
  chart?: { label: string; points: { label: string; value: number }[] } | null;
  rows?: Table | null;
  extra?: Table | null;
}
interface Props {
  period: string;
  periods: Record<string, string>;
  branch: string;
  branches: { id: string; name: string }[];
  allBranches: boolean;
  range: { from: string; to: string };
  highlights: string[];
  sections: Section[];
}

const show = (v: number, f: Metric['format']) =>
  f === 'money' ? rand(v) : f === 'percent' ? `${v}%` : String(v);
const change = (now: number, before: number) =>
  before === 0 ? null : Math.round(((now - before) / Math.abs(before)) * 1000) / 10;

/** Reports → Overview: every module's section, compared with the previous period. */
export default function Overview(p: Props) {
  const go = (q: Record<string, string>) =>
    router.get(
      window.location.pathname,
      { period: p.period, branch: p.branch, ...q },
      { preserveScroll: true },
    );
  return (
    <AppShell title="Reports">
      <Head title="Reports" />
      <div className="grid max-w-5xl gap-6">
        <PageHeader
          title="Reports"
          description={`${p.range.from} to ${p.range.to}, compared with the period before.`}
        />
        <div className="flex flex-wrap gap-3 text-sm">
          <label className="flex items-center gap-2">
            Period
            <select
              className="border-border bg-bg h-10 rounded-md border px-3"
              value={p.period}
              onChange={(e) => go({ period: e.target.value })}
            >
              {Object.entries(p.periods).map(([k, label]) => (
                <option key={k} value={k}>
                  {label}
                </option>
              ))}
            </select>
          </label>
          {p.branches.length > 0 && (
            <label className="flex items-center gap-2">
              Branch
              <select
                className="border-border bg-bg h-10 rounded-md border px-3"
                value={p.branch}
                onChange={(e) => go({ branch: e.target.value })}
              >
                <option value="">{p.allBranches ? 'All branches' : 'All my branches'}</option>
                {p.branches.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name}
                  </option>
                ))}
              </select>
            </label>
          )}
        </div>

        {p.highlights.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Highlights</CardTitle>
            </CardHeader>
            <CardContent>
              <ul aria-label="Highlights" className="grid list-disc gap-1 pl-5 text-sm">
                {p.highlights.map((h) => (
                  <li key={h}>{h}</li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}

        {p.sections.length === 0 ? (
          <EmptyState
            icon={BarChart3}
            title="Nothing to report yet"
            description="Reports fill in as you take orders, bookings and customers."
          />
        ) : (
          p.sections.map((s) => <SectionCard key={s.key} s={s} />)
        )}
      </div>
    </AppShell>
  );
}

function SectionCard({ s }: { s: Section }) {
  const max = Math.max(1, ...(s.chart?.points.map((pt) => pt.value) ?? [1]));
  return (
    <Card>
      <CardHeader>
        <CardTitle>{s.title}</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-4 text-sm">
        <ul aria-label={`${s.title} figures`} className="grid gap-3 sm:grid-cols-3">
          {s.metrics.map((m) => {
            const c = change(m.value, m.previous);
            return (
              <li key={m.label} className="border-border grid gap-1 rounded-lg border p-3">
                <span className="text-fg-muted">{m.label}</span>
                <span className="text-xl font-semibold">{show(m.value, m.format)}</span>
                <span className="text-fg-muted">
                  {c === null
                    ? 'Nothing to compare with'
                    : `${c >= 0 ? '▲' : '▼'} ${Math.abs(c)}% (was ${show(m.previous, m.format)})`}
                </span>
              </li>
            );
          })}
        </ul>
        {s.chart && s.chart.points.length > 0 && (
          <div>
            <p className="text-fg-muted mb-1">{s.chart.label}</p>
            <div aria-hidden="true" className="flex h-24 items-end gap-1">
              {s.chart.points.map((pt) => (
                <div
                  key={pt.label}
                  className="bg-primary flex-1 rounded-t"
                  style={{ height: `${Math.max(2, (Math.max(0, pt.value) / max) * 100)}%` }}
                  title={pt.label}
                />
              ))}
            </div>
            <table className="sr-only">
              <caption>{s.chart.label}</caption>
              <tbody>
                {s.chart.points.map((pt) => (
                  <tr key={pt.label}>
                    <th scope="row">{pt.label}</th>
                    <td>{pt.value}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {[s.rows, s.extra].map((t, i) =>
          t && t.data.length > 0 ? (
            <div key={i} className="overflow-x-auto">
              <table className="w-full text-left">
                <thead>
                  <tr>
                    {t.columns.map((c) => (
                      <th key={c} scope="col">
                        {c}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {t.data.map((r, j) => (
                    <tr key={j}>
                      {r.map((cell, k) => (
                        <td key={k}>{cell}</td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null,
        )}
      </CardContent>
    </Card>
  );
}
