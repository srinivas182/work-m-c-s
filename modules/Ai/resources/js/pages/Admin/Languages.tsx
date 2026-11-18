import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';

interface Language {
  code: string;
  name: string;
  total: number;
  translated: number;
  reviewed: number;
  enabled: boolean;
  preview: boolean;
}

const pct = (n: number, total: number) => (total === 0 ? 0 : Math.floor((n / total) * 100));

/** Admin → Languages (24.2): each language's progress; drafts by WURK AI; review in context. */
export default function Languages({
  languages,
  canManage,
}: {
  languages: Language[];
  canManage: boolean;
}) {
  return (
    <AppShell title="Languages">
      <Head title="Languages" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Languages"
          description="WURK AI drafts each language; people review it. A language shows as “preview” until 95% of its texts are reviewed."
        />
        <Card>
          <CardHeader>
            <CardTitle>Progress</CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="grid gap-4 text-sm">
              {languages.map((l) => (
                <li key={l.code} className="border-border grid gap-2 border-b pb-4 last:border-0">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <Link className="font-medium underline" href={`/settings/languages/${l.code}`}>
                      {l.name}
                    </Link>
                    <span className="text-fg-muted">
                      {l.preview ? 'Preview' : 'Reviewed'} · {pct(l.translated, l.total)}% drafted ·{' '}
                      {pct(l.reviewed, l.total)}% reviewed
                    </span>
                  </div>
                  <div
                    className="bg-muted h-2 overflow-hidden rounded-full"
                    role="img"
                    aria-label={`${l.name}: ${pct(l.reviewed, l.total)}% reviewed, ${pct(l.translated, l.total)}% drafted`}
                  >
                    <div
                      className="bg-brand-9 h-full"
                      style={{ width: `${pct(l.reviewed, l.total)}%` }}
                    />
                  </div>
                  {canManage && (
                    <div className="flex flex-wrap items-center gap-3">
                      {l.translated < l.total && (
                        <Button
                          size="sm"
                          variant="secondary"
                          aria-label={`Draft the missing texts with WURK AI: ${l.name}`}
                          onClick={() =>
                            router.post(
                              `/settings/languages/${l.code}/draft`,
                              {},
                              { preserveScroll: true },
                            )
                          }
                        >
                          Draft the missing texts with WURK AI
                        </Button>
                      )}
                      <label className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          checked={l.enabled}
                          onChange={(e) =>
                            router.put(
                              `/settings/languages/${l.code}/offered`,
                              { enabled: e.target.checked },
                              { preserveScroll: true },
                            )
                          }
                        />
                        Offered to everyone
                      </label>
                    </div>
                  )}
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
