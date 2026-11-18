import { Head, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  EmptyState,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Props {
  days: number;
  groups: {
    type: string;
    label: string;
    items: { id: string; name: string; deleted_at: string; gone_at: string }[];
  }[];
}

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const day = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });

/** Settings → Recently deleted (Sprint 16.6b): put things back for 30 days. */
export default function RecentlyDeleted({ days, groups }: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  const any = groups.some((g) => g.items.length > 0);
  return (
    <AppShell title={__('Recently deleted')}>
      <Head title={__('Recently deleted')} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title={__('Recently deleted')}
          description={`Deleted something by mistake? Put it back within ${days} days. After that it’s gone for good.`}
        />
        {!any && (
          <EmptyState
            icon={Trash2}
            title={__('Nothing deleted lately')}
            description={`Anything you delete stays here for ${days} days.`}
          />
        )}
        {groups
          .filter((g) => g.items.length > 0)
          .map((g) => (
            <Card key={g.type}>
              <CardHeader>
                <CardTitle>{g.label}</CardTitle>
              </CardHeader>
              <CardContent>
                <ul aria-label={g.label} className="divide-border grid divide-y text-sm">
                  {g.items.map((i) => (
                    <li key={i.id} className="flex flex-wrap items-center gap-3 py-3">
                      <strong>{i.name}</strong>
                      <span className="text-fg-muted">
                        {__('Deleted')} {when.format(new Date(i.deleted_at))}{' '}
                        {__('· gone for good')} {day.format(new Date(i.gone_at))}
                      </span>
                      <Button
                        size="sm"
                        variant="secondary"
                        className="ml-auto"
                        aria-label={`Restore ${i.name}`}
                        onClick={() =>
                          router.post(`${base}/${g.type}/${i.id}`, {}, { preserveScroll: true })
                        }
                      >
                        {__('Restore')}
                      </Button>
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          ))}
      </div>
    </AppShell>
  );
}
