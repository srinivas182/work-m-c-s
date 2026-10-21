import { Head, router } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { Card, CardContent, EmptyState, Label, PageHeader, Switch } from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Feature {
  key: string;
  label: string;
  description: string;
  on: boolean;
  default_on_at: string | null;
}

export default function Business({ features }: { features: Feature[] }) {
  return (
    <AppShell title="New features">
      <Head title="New features" />
      <div className="grid max-w-3xl gap-8">
        <PageHeader
          title="New features"
          description="Try improvements before they become standard. You can switch them off again until then."
        />
        {features.length === 0 ? (
          <EmptyState
            icon={Sparkles}
            title="Nothing new to try right now"
            description="When WURK releases an optional improvement, it appears here first."
          />
        ) : (
          <Card>
            <CardContent className="divide-border divide-y p-0">
              {features.map((f) => (
                <div key={f.key} className="flex items-start justify-between gap-6 p-6">
                  <div>
                    <Label htmlFor={`f-${f.key}`} className="text-base">
                      {f.label}
                    </Label>
                    <p className="text-fg-muted mt-1 text-sm">{f.description}</p>
                    {f.default_on_at && (
                      <p className="text-fg-muted mt-1 text-xs">
                        Standard for everyone from {new Date(f.default_on_at).toLocaleDateString()}.
                      </p>
                    )}
                  </div>
                  <Switch
                    id={`f-${f.key}`}
                    checked={f.on}
                    onCheckedChange={(on) =>
                      router.put(`/settings/features/${f.key}`, { on }, { preserveScroll: true })
                    }
                  />
                </div>
              ))}
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
