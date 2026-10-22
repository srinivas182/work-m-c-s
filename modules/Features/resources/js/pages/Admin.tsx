import { Head, useForm } from '@inertiajs/react';
import { Flag } from 'lucide-react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  EmptyState,
  Field,
  Input,
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Feature {
  key: string;
  label: string;
  description: string;
  module: string;
  opt_ins: number;
  rule: {
    mode: 'off' | 'opt_in' | 'percentage' | 'on';
    percentage: number;
    default_on_at: string | null;
  };
}

const MODES = [
  { value: 'off', label: 'Off (kill switch)' },
  { value: 'opt_in', label: 'Businesses can opt in' },
  { value: 'percentage', label: 'Gradual rollout' },
  { value: 'on', label: 'On for everyone' },
];

function FeatureCard({ feature, windowDays }: { feature: Feature; windowDays: number }) {
  const form = useForm({ mode: feature.rule.mode, percentage: feature.rule.percentage });
  return (
    <Card>
      <CardHeader>
        <div className="flex items-start justify-between gap-3">
          <div>
            <CardTitle>{feature.label}</CardTitle>
            <CardDescription>{feature.description}</CardDescription>
          </div>
          <Badge
            tone={
              feature.rule.mode === 'off'
                ? 'neutral'
                : feature.rule.mode === 'on'
                  ? 'success'
                  : 'brand'
            }
          >
            {MODES.find((m) => m.value === feature.rule.mode)?.label}
          </Badge>
        </div>
      </CardHeader>
      <CardContent>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            form.put(`/features/${feature.key}`, { preserveScroll: true });
          }}
          className="grid grid-cols-1 items-end gap-4 sm:grid-cols-[1.5fr_1fr_auto]"
        >
          <Field label="Rollout">
            {(control) => (
              <Select
                value={form.data.mode}
                onValueChange={(v) => form.setData('mode', v as Feature['rule']['mode'])}
              >
                <SelectTrigger {...control}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {MODES.map((m) => (
                    <SelectItem key={m.value} value={m.value}>
                      {m.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </Field>
          {form.data.mode === 'percentage' ? (
            <Field label="Businesses (%)" error={form.errors.percentage}>
              <Input
                type="number"
                min={0}
                max={100}
                value={form.data.percentage}
                onChange={(e) => form.setData('percentage', Number(e.target.value))}
              />
            </Field>
          ) : (
            <p className="text-fg-muted text-sm">
              {form.data.mode === 'opt_in'
                ? feature.rule.default_on_at
                  ? `${feature.opt_ins} opted in · on for everyone from ${new Date(feature.rule.default_on_at).toLocaleDateString()}`
                  : `On for everyone ${windowDays} days after opt-in opens`
                : ''}
            </p>
          )}
          <Button type="submit" loading={form.processing}>
            Save
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

export default function Admin({
  features,
  windowDays,
}: {
  features: Feature[];
  windowDays: number;
}) {
  return (
    <AppShell title="Feature rollouts">
      <Head title="Feature rollouts" />
      <div className="grid gap-8">
        <PageHeader
          title="Feature rollouts"
          description="Release new features safely: let businesses opt in, roll out to a percentage, or switch off instantly."
        />
        {features.length === 0 ? (
          <EmptyState
            icon={Flag}
            title="No features to roll out yet"
            description="Modules declare new features as they’re built; they appear here, off by default."
          />
        ) : (
          <div className="grid gap-6">
            {features.map((f) => (
              <FeatureCard key={f.key} feature={f} windowDays={windowDays} />
            ))}
          </div>
        )}
      </div>
    </AppShell>
  );
}
