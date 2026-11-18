import { Head, Link } from '@inertiajs/react';
import { Activity, Building2, Database, Plus, UserCog } from 'lucide-react';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/design-system/components/card';
import { EmptyState, PageHeader, StatCard } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { auditLabel } from '@/lib/audit-labels';
import { __ } from '@/i18n';

interface Props {
  businesses: {
    active: number;
    suspended: number;
    provisioning: number;
    shards: number;
    capacity: number;
    used: number;
  };
  staff: number;
  recent: { at: string; actor: string; action: string }[];
}

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

export default function Dashboard({ businesses, staff, recent }: Props) {
  const usedPct =
    businesses.capacity > 0 ? Math.round((businesses.used / businesses.capacity) * 1000) / 10 : 0;

  return (
    <AppShell title={__('Dashboard')}>
      <Head title={__('Dashboard')} />
      <div className="grid gap-8">
        <PageHeader
          eyebrow="WURK admin"
          title={__('Dashboard')}
          description={__(
            'Revenue and growth metrics arrive with billing. For now: businesses, capacity and recent activity.',
          )}
          actions={
            <Button asChild>
              <Link href="/businesses/new">
                <Plus /> {__('New business')}
              </Link>
            </Button>
          }
        />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <StatCard
            label={__('Active businesses')}
            value={businesses.active.toLocaleString()}
            icon={Building2}
            hint={`${businesses.suspended} suspended`}
          />
          <StatCard label={__('WURK staff')} value={staff.toLocaleString()} icon={UserCog} />
          <StatCard
            label={__('Shared databases')}
            value={businesses.shards.toLocaleString()}
            icon={Database}
            hint={`${businesses.used.toLocaleString()} businesses placed`}
          />
          <StatCard
            label={__('Capacity used')}
            value={`${usedPct}%`}
            icon={Activity}
            hint={`of ${businesses.capacity.toLocaleString()} places`}
          />
        </div>
        <Card>
          <CardHeader>
            <CardTitle>{__('Recent platform activity')}</CardTitle>
            <CardDescription>{__('From the platform audit log.')}</CardDescription>
          </CardHeader>
          <CardContent>
            {recent.length === 0 ? (
              <EmptyState
                icon={Activity}
                title={__('Nothing yet')}
                description={__('Sign-ins and changes will appear here.')}
              />
            ) : (
              <ul className="divide-border divide-y">
                {recent.map((r, i) => (
                  <li
                    key={i}
                    className="flex flex-col gap-0.5 py-3 sm:flex-row sm:items-center sm:justify-between"
                  >
                    <span>
                      <span className="font-medium">{r.actor}</span>{' '}
                      <span className="text-fg-muted">· {auditLabel(r.action)}</span>
                    </span>
                    <span className="text-fg-muted text-sm tabular-nums">
                      {when.format(new Date(r.at))}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
