import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { BarChart3 } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle, EmptyState, PageHeader } from '@/design-system';
import { type Section, SectionCard } from '@/components/ReportSections';
import AppShell from '@/layouts/AppShell';

interface Props {
  period: string;
  periods: Record<string, string>;
  branch: string;
  branches: { id: string; name: string }[];
  allBranches: boolean;
  range: { from: string; to: string };
  highlights: string[];
  sections: Section[];
  summary: 'weekly' | 'monthly' | 'off' | null; // owners only
}

/** Reports → Overview: every module's section, compared with the previous period. */
export default function Overview(p: Props) {
  const [dates, setDates] = useState({ from: p.range.from, to: p.range.to });
  const custom: Record<string, string> =
    p.period === 'custom' ? { from: p.range.from, to: p.range.to } : {};
  const go = (q: Record<string, string>) =>
    router.get(
      window.location.pathname,
      { period: p.period, branch: p.branch, ...custom, ...q },
      { preserveScroll: true },
    );
  const exportQuery = new URLSearchParams({
    period: p.period,
    branch: p.branch,
    ...custom,
  }).toString();
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
              onChange={(e) =>
                e.target.value === 'custom'
                  ? go({ period: 'custom', ...dates })
                  : router.get(
                      window.location.pathname,
                      { period: e.target.value, branch: p.branch },
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
                go(dates);
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

        <div className="flex flex-wrap items-center gap-3 text-sm">
          <a className="underline" href={`${window.location.pathname}/export/csv?${exportQuery}`}>
            Download CSV
          </a>
          <a className="underline" href={`${window.location.pathname}/export/pdf?${exportQuery}`}>
            Download PDF
          </a>
          {p.summary !== null && (
            <label className="flex items-center gap-2">
              Email me a summary
              <select
                className="border-border bg-bg h-10 rounded-md border px-3"
                value={p.summary}
                onChange={(e) =>
                  router.put(
                    `${window.location.pathname}/summary`,
                    { summary: e.target.value },
                    { preserveScroll: true },
                  )
                }
              >
                <option value="weekly">Weekly (Mondays)</option>
                <option value="monthly">Monthly (the 1st)</option>
                <option value="off">Off</option>
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
