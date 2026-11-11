import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { BarChart3 } from 'lucide-react';
import { type Section, SectionCard } from '@/components/ReportSections';
import { Card, CardContent, CardHeader, CardTitle, EmptyState, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Props {
  period: string;
  periods: Record<string, string>;
  range: { from: string; to: string };
  highlights: string[];
  sections: Section[];
}

/** Admin → Metrics: WURK's own SaaS metrics. */
export default function Metrics(p: Props) {
  const [dates, setDates] = useState({ from: p.range.from, to: p.range.to });
  const query = new URLSearchParams({
    period: p.period,
    ...(p.period === 'custom' ? { from: p.range.from, to: p.range.to } : {}),
  }).toString();
  return (
    <AppShell title="Metrics">
      <Head title="Metrics" />
      <div className="grid max-w-5xl gap-6">
        <PageHeader
          title="Metrics"
          description={`${p.range.from} to ${p.range.to}, compared with the period before. Amounts exclude VAT.`}
        />
        <label className="flex items-center gap-2 text-sm">
          Period
          <select
            className="border-border bg-bg h-10 rounded-md border px-3"
            value={p.period}
            onChange={(e) =>
              router.get(
                window.location.pathname,
                { period: e.target.value, ...(e.target.value === 'custom' ? dates : {}) },
                { preserveScroll: true },
              )
            }
          >
            {Object.entries(p.periods).map(([k, label]) => (
              <option key={k} value={k}>
                {label}
              </option>
            ))}
          </select>
        </label>
        {p.period === 'custom' && (
          <form
            aria-label="Dates"
            className="flex flex-wrap items-end gap-2 text-sm"
            onSubmit={(e) => {
              e.preventDefault();
              router.get(
                window.location.pathname,
                { period: 'custom', ...dates },
                { preserveScroll: true },
              );
            }}
          >
            <label className="grid gap-1">
              From
              <input
                type="date"
                className="border-border bg-bg h-10 rounded-md border px-3"
                value={dates.from}
                onChange={(e) => setDates({ ...dates, from: e.target.value })}
              />
            </label>
            <label className="grid gap-1">
              To
              <input
                type="date"
                className="border-border bg-bg h-10 rounded-md border px-3"
                value={dates.to}
                onChange={(e) => setDates({ ...dates, to: e.target.value })}
              />
            </label>
            <button type="submit" className="h-10 underline">
              Apply
            </button>
          </form>
        )}
        <a
          className="justify-self-start text-sm underline"
          href={`${window.location.pathname}/export.csv?${query}`}
        >
          Download CSV
        </a>
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
            title="Nothing to show yet"
            description="Metrics fill in as businesses subscribe."
          />
        ) : (
          p.sections.map((s) => <SectionCard key={s.key} s={s} />)
        )}
      </div>
    </AppShell>
  );
}
