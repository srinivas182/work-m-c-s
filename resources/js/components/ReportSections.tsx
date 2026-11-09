import { Card, CardContent, CardHeader, CardTitle } from '@/design-system';
import { rand } from '@/lib/money';

// A report section card (Sprint 15.1, shared with Admin → Metrics in 15.4): figures with the change on the period
// before, an optional bar chart (with a table for screen readers), and tables.
export interface Metric {
  label: string;
  value: number;
  previous: number;
  format: 'money' | 'count' | 'percent';
  snapshot?: boolean; // a figure for right now, not the period (e.g. stock)
}
export interface Table {
  columns: string[];
  data: string[][];
}
export interface Section {
  key: string;
  title: string;
  metrics: Metric[];
  chart?: { label: string; points: { label: string; value: number }[] } | null;
  rows?: Table | null;
  extra?: Table | null;
  note?: string;
}
const show = (v: number, f: Metric['format']) =>
  f === 'money' ? rand(v) : f === 'percent' ? `${v}%` : String(v);
const change = (now: number, before: number) =>
  before === 0 ? null : Math.round(((now - before) / Math.abs(before)) * 1000) / 10;

export function SectionCard({ s }: { s: Section }) {
  const max = Math.max(1, ...(s.chart?.points.map((pt) => pt.value) ?? [1]));
  return (
    <Card>
      <CardHeader>
        <CardTitle>{s.title}</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-4 text-sm">
        {s.note && <p className="text-fg-muted">{s.note}</p>}
        <ul aria-label={`${s.title} figures`} className="grid gap-3 sm:grid-cols-3">
          {s.metrics.map((m) => {
            const c = change(m.value, m.previous);
            return (
              <li key={m.label} className="border-border grid gap-1 rounded-lg border p-3">
                <span className="text-fg-muted">{m.label}</span>
                <span className="text-xl font-semibold">{show(m.value, m.format)}</span>
                <span className="text-fg-muted">
                  {m.snapshot
                    ? 'Right now'
                    : c === null
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
