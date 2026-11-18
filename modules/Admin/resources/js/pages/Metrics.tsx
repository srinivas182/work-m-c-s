import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { BarChart3 } from 'lucide-react';
import { type Section, SectionCard } from '@/components/ReportSections';
import { Card, CardContent, CardHeader, CardTitle, EmptyState, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

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
    <AppShell title={__('Metrics')}>
      <Head title={__('Metrics')} />
      <div className="grid max-w-5xl gap-6">
        <PageHeader
          title={__('Metrics')}
          description={`${p.range.from} to ${p.range.to}, compared with the period before. Amounts exclude VAT.`}
        />
        <label className="flex items-center gap-2 text-sm">
          {__('Period')}
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
            aria-label={__('Dates')}
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
              {__('From')}
              <input
                type="date"
                className="border-border bg-bg h-10 rounded-md border px-3"
                value={dates.from}
                onChange={(e) => setDates({ ...dates, from: e.target.value })}
              />
            </label>
            <label className="grid gap-1">
              {__('To')}
              <input
                type="date"
                className="border-border bg-bg h-10 rounded-md border px-3"
                value={dates.to}
                onChange={(e) => setDates({ ...dates, to: e.target.value })}
              />
            </label>
            <button type="submit" className="h-10 underline">
              {__('Apply')}
            </button>
          </form>
        )}
        <a
          className="justify-self-start text-sm underline"
          href={`${window.location.pathname}/export.csv?${query}`}
        >
          {__('Download CSV')}
        </a>
        {p.highlights.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>{__('Highlights')}</CardTitle>
            </CardHeader>
            <CardContent>
              <ul aria-label={__('Highlights')} className="grid list-disc gap-1 pl-5 text-sm">
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
            title={__('Nothing to show yet')}
            description={__('Metrics fill in as businesses subscribe.')}
          />
        ) : (
          p.sections.map((s) => <SectionCard key={s.key} s={s} />)
        )}
      </div>
    </AppShell>
  );
}
