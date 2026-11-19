import { Head, Link, router } from '@inertiajs/react';
import { CircleCheck, Rocket } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { EmptyState, PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Tile {
  key: string;
  label: string;
  value: string;
  hint?: string;
  href?: string;
}

/** The business home: tiles from the modules this person may see (Sprint 13.9a). */
interface NeedAction {
  label: string;
  url: string;
  data: Record<string, string>;
  tone: 'primary' | 'secondary';
  link?: boolean;
}
interface Need {
  id: string;
  kind: string;
  title: string;
  detail: string;
  at: string;
  url: string | null;
  actions: NeedAction[];
}

/** Home's "Needs you now" (Sprint 26.3): one list across WURK, newest first, each with its action right there. */
function NeedsYouNow({ needs }: { needs: Need[] }) {
  const [busy, setBusy] = useState(false);
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Needs you now')}</CardTitle>
      </CardHeader>
      <CardContent>
        {needs.length === 0 ? (
          <p className="text-fg-muted flex items-center gap-2 text-sm">
            <CircleCheck aria-hidden className="text-success-11 size-5" />
            {__('All done for now.')}
          </p>
        ) : (
          <ul aria-label={__('Needs you now')} className="divide-border grid divide-y">
            {needs.map((n) => (
              <li
                key={n.id}
                className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
              >
                <div className="min-w-0 flex-1">
                  <p className="text-fg-muted text-xs font-medium tracking-wide uppercase">
                    {n.kind}
                  </p>
                  <p className="font-medium">
                    {n.url ? (
                      <Link href={n.url} className="underline-offset-2 hover:underline">
                        {n.title}
                      </Link>
                    ) : (
                      n.title
                    )}
                  </p>
                  {n.detail && <p className="text-fg-muted text-sm">{n.detail}</p>}
                </div>
                <div className="flex flex-wrap gap-2">
                  {n.actions.map((a) =>
                    a.link ? (
                      <Button key={a.label} size="sm" variant={a.tone} asChild>
                        <Link href={a.url}>{a.label}</Link>
                      </Button>
                    ) : (
                      <Button
                        key={a.label}
                        size="sm"
                        variant={a.tone}
                        disabled={busy}
                        aria-label={`${a.label}: ${n.title}`}
                        onClick={() => {
                          setBusy(true);
                          router.post(a.url, a.data, {
                            preserveScroll: true,
                            onFinish: () => setBusy(false),
                          });
                        }}
                      >
                        {a.label}
                      </Button>
                    ),
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

export default function Workspace({
  business,
  tiles = [],
  needs = [],
}: {
  business: { name: string; handle: string };
  tiles?: Tile[];
  needs?: Need[];
}) {
  return (
    <AppShell title={business.name}>
      <Head title={business.name} />
      <div className="grid gap-8">
        <PageHeader eyebrow="Business back office" title={`Welcome to ${business.name}`} />
        <NeedsYouNow needs={needs} />
        {tiles.length > 0 ? (
          <ul aria-label={__('At a glance')} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {tiles.map((t) => (
              <li key={t.key}>
                <Card>
                  <CardContent className="grid gap-1 pt-6">
                    <span className="text-fg-muted text-sm">{t.label}</span>
                    <span className="text-2xl font-semibold">{t.value}</span>
                    {t.hint && <span className="text-fg-muted text-sm">{t.hint}</span>}
                    {t.href && (
                      <Link className="text-sm underline" href={t.href}>
                        {__('See the report')}
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
            title={__('You’re signed in')}
            description={__(
              'Bookings, your shop, customers and reports will appear here as each module is delivered.',
            )}
            action={
              <Button variant="secondary" asChild>
                <Link href="/account/security">{__('Review account security')}</Link>
              </Button>
            }
          />
        )}
      </div>
    </AppShell>
  );
}
