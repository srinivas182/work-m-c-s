import { Head, router } from '@inertiajs/react';
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
                { period: e.target.value },
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
