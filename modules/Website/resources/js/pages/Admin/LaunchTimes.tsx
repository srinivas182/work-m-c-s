import { Head } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/design-system/components/card';
import { EmptyState, PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

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
    <AppShell title={__('Time to launch')}>
      <Head title={__('Time to launch')} />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title={__('Time to launch')}
          description={__(
            'From creating a business to first publishing its website. The goal: a premium site in under an hour.',
          )}
        />
        {count === 0 ? (
          <EmptyState
            icon={Clock}
            title={__('No launches yet')}
            description={__(
              'Each business appears here the first time it publishes its home page.',
            )}
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
                  <caption className="sr-only">{__('Recent launches')}</caption>
                  <thead>
                    <tr className="text-fg-muted text-left">
                      <th className="px-4 py-3 font-medium">{__('Business')}</th>
                      <th className="px-4 py-3 font-medium">{__('Template')}</th>
                      <th className="px-4 py-3 text-right font-medium">{__('Time to launch')}</th>
                      <th className="px-4 py-3 text-right font-medium">{__('Launched')}</th>
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
