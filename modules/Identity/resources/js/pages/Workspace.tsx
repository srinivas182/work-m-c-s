import { Head, Link } from '@inertiajs/react';
import { Rocket } from 'lucide-react';
import { Button, Card, CardContent, EmptyState, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Tile {
  key: string;
  label: string;
  value: string;
  hint?: string;
  href?: string;
}

/** The business home: tiles from the modules this person may see (Sprint 13.9a). */
export default function Workspace({
  business,
  tiles = [],
}: {
  business: { name: string; handle: string };
  tiles?: Tile[];
}) {
  return (
    <AppShell title={business.name}>
      <Head title={business.name} />
      <div className="grid gap-8">
        <PageHeader eyebrow="Business back office" title={`Welcome to ${business.name}`} />
        {tiles.length > 0 ? (
          <ul aria-label="At a glance" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {tiles.map((t) => (
              <li key={t.key}>
                <Card>
                  <CardContent className="grid gap-1 pt-6">
                    <span className="text-fg-muted text-sm">{t.label}</span>
                    <span className="text-2xl font-semibold">{t.value}</span>
                    {t.hint && <span className="text-fg-muted text-sm">{t.hint}</span>}
                    {t.href && (
                      <Link className="text-sm underline" href={t.href}>
                        See the report
                      </Link>
                    )}
                  </CardContent>
                </Card>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState
            icon={Rocket}
            title="You’re signed in"
            description="Bookings, your shop, customers and reports will appear here as each module is delivered."
            action={
              <Button variant="secondary" asChild>
                <Link href="/account/security">Review account security</Link>
              </Button>
            }
          />
        )}
      </div>
    </AppShell>
  );
}
