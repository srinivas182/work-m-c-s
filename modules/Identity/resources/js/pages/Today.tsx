import { Head, Link, router } from '@inertiajs/react';
import { Sun } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { EmptyState, PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Action {
  label: string;
  url: string;
  data: Record<string, unknown>;
  tone: 'primary' | 'secondary' | 'danger';
}
interface Item {
  id: string;
  title: string;
  detail: string;
  time: string | null;
  badge: string | null;
  url: string | null;
  actions: Action[];
}
interface Section {
  key: string;
  title: string;
  empty: string | null;
  link: { label: string; url: string } | null;
  items: Item[];
}
interface Props {
  date: string;
  sections: Section[];
}

const variant = (tone: Action['tone']) =>
  tone === 'primary' ? 'primary' : tone === 'danger' ? 'ghost' : 'secondary';

/**
 * Today (Sprint 26.2): where the WURK app opens. What this person has to do today — their next bookings, chats
 * waiting, orders to pack — each with its buttons, big enough for a thumb. Works with lite mode: on a weak signal,
 * the buttons queue and send when the phone is back online.
 */
export default function Today({ date, sections }: Props) {
  const [busy, setBusy] = useState<string | null>(null);
  const run = (key: string, a: Action) => {
    setBusy(key);
    router.post(a.url, a.data as Record<string, string>, {
      preserveScroll: true,
      onFinish: () => setBusy(null),
    });
  };

  return (
    <AppShell title={__('Today')}>
      <Head title={__('Today')} />
      <div className="mx-auto grid max-w-2xl gap-6">
        <PageHeader title={__('Today')} description={date} />
        {sections.length === 0 && (
          <EmptyState
            icon={Sun}
            title={__('Nothing for you today')}
            description={__('Your bookings, chats and orders show here when there are some.')}
          />
        )}
        {sections.map((s) => (
          <Card key={s.key}>
            <CardHeader className="flex flex-row items-center justify-between gap-3">
              <CardTitle>{s.title}</CardTitle>
              {s.link && (
                <Link href={s.link.url} className="text-sm font-medium underline">
                  {s.link.label}
                </Link>
              )}
            </CardHeader>
            <CardContent>
              {s.items.length === 0 ? (
                <p className="text-fg-muted text-sm">{s.empty}</p>
              ) : (
                <ul className="divide-border grid divide-y" aria-label={s.title}>
                  {s.items.map((item) => (
                    <li key={item.id} className="grid gap-3 py-3 first:pt-0 last:pb-0">
                      <div className="flex items-start gap-3">
                        {item.time && (
                          <span className="w-12 shrink-0 font-semibold tabular-nums">
                            {item.time}
                          </span>
                        )}
                        <div className="min-w-0 flex-1">
                          <p className="font-medium">
                            {item.url ? (
                              <Link href={item.url} className="underline-offset-2 hover:underline">
                                {item.title}
                              </Link>
                            ) : (
                              item.title
                            )}
                          </p>
                          {item.detail && <p className="text-fg-muted text-sm">{item.detail}</p>}
                        </div>
                        {item.badge && <Badge>{item.badge}</Badge>}
                      </div>
                      {item.actions.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                          {item.actions.map((a, i) => (
                            <Button
                              key={i}
                              size="sm"
                              variant={variant(a.tone)}
                              disabled={busy !== null}
                              aria-label={`${a.label}: ${item.title}`}
                              onClick={() => run(`${item.id}:${i}`, a)}
                            >
                              {a.label}
                            </Button>
                          ))}
                        </div>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        ))}
      </div>
    </AppShell>
  );
}
