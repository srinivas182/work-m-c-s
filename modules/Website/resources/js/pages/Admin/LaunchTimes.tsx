import { Head } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  EmptyState,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Props {
  median: number | null;
  withinHour: number | null;
  count: number;
  launches: {
    business: string;
    template: string | null;
    minutes: number | null;
    launched: string;
  }[];
}

const duration = (m: number | null) =>
  m === null ? '—' : m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${m % 60} min`;

export default function LaunchTimes({ median, withinHour, count, launches }: Props) {
  return (
    <AppShell title="Time to launch">
      <Head title="Time to launch" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Time to launch"
          description="From creating a business to first publishing its website. The goal: a premium site in under an hour."
        />
        {count === 0 ? (
          <EmptyState
            icon={Clock}
            title="No launches yet"
            description="Each business appears here the first time it publishes its home page."
          />
        ) : (
          <>
            <div className="grid gap-4 sm:grid-cols-3">
              {[
                ['Median time to launch', duration(median)],
                ['Launched within an hour', withinHour === null ? '—' : `${withinHour}%`],
                ['Businesses launched', String(count)],
              ].map(([label, value]) => (
                <Card key={label}>
                  <CardHeader>
                    <CardDescription>{label}</CardDescription>
                    <CardTitle className="text-3xl tabular-nums">{value}</CardTitle>
                  </CardHeader>
                </Card>
              ))}
            </div>
            <Card>
              <CardContent className="overflow-x-auto p-0">
                <table className="w-full text-sm">
                  <caption className="sr-only">Recent launches</caption>
                  <thead>
                    <tr className="text-fg-muted text-left">
                      <th className="px-4 py-3 font-medium">Business</th>
                      <th className="px-4 py-3 font-medium">Template</th>
                      <th className="px-4 py-3 text-right font-medium">Time to launch</th>
                      <th className="px-4 py-3 text-right font-medium">Launched</th>
                    </tr>
                  </thead>
                  <tbody>
                    {launches.map((l) => (
                      <tr key={l.business + l.launched} className="border-border border-t">
                        <td className="px-4 py-3">{l.business}</td>
                        <td className="text-fg-muted px-4 py-3">{l.template ?? '—'}</td>
                        <td
                          className={`px-4 py-3 text-right tabular-nums ${l.minutes !== null && l.minutes > 60 ? 'text-warning-11' : ''}`}
                        >
                          {duration(l.minutes)}
                        </td>
                        <td className="text-fg-muted px-4 py-3 text-right">
                          {new Date(l.launched).toLocaleDateString('en-ZA')}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </CardContent>
            </Card>
          </>
        )}
      </div>
    </AppShell>
  );
}
