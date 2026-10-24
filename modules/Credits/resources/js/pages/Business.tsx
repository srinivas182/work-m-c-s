import { Head, useForm } from '@inertiajs/react';
import { Download } from 'lucide-react';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';
import { CreditOverview, type CreditSummary } from './Summary';

const day = (iso: string) =>
  new Date(iso).toLocaleDateString('en-ZA', { day: 'numeric', month: 'long', year: 'numeric' });
const num = (n: number) => (Number.isInteger(n) ? String(n) : n.toFixed(1));

/** Used vs included, as an accessible progress bar. */
function Meter({
  label,
  used,
  included,
  unit,
}: {
  label: string;
  used: number;
  included: number | null;
  unit: string;
}) {
  const pct = included ? Math.min(100, Math.round((used / included) * 100)) : 0;
  const text = `${num(used)}${unit ? ` ${unit}` : ''}${included ? ` of ${num(included)}${unit ? ` ${unit}` : ''}` : ''}`;
  return (
    <div className="grid gap-1.5">
      <div className="flex justify-between gap-3 text-sm">
        <span className="font-medium">{label}</span>
        <span className="text-fg-muted tabular-nums">{text}</span>
      </div>
      {included ? (
        <div
          role="progressbar"
          aria-label={label}
          aria-valuenow={pct}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuetext={text}
          className="bg-surface h-2 overflow-hidden rounded-full"
        >
          <div
            className={`h-full rounded-full ${pct >= 100 ? 'bg-danger-9' : pct >= 80 ? 'bg-warning-9' : 'bg-brand-9'}`}
            style={{ width: `${pct}%` }}
          />
        </div>
      ) : null}
    </div>
  );
}

export default function Business(summary: CreditSummary) {
  const alert = useForm({ low_balance: summary.lowBalance });
  const limit = useForm<{ monthly: number | '' }>({ monthly: summary.limits?.monthly ?? '' });
  const base = window.location.pathname;
  const value = summary.randPerCredit
    ? ` (about ${rand(Math.round(summary.balance * summary.randPerCredit * 100))})`
    : '';

  return (
    <AppShell title="Plan & usage">
      <Head title="Plan & usage" />
      <div className="grid max-w-5xl gap-8">
        <PageHeader
          title="Plan & usage"
          description="What your package includes, what you’ve used this month, and what things cost."
        />

        <Card>
          <CardHeader>
            <CardTitle>Your plan this month</CardTitle>
            {summary.nextPayment && (
              <CardDescription>
                Renews on {day(summary.nextPayment.date)} — {rand(summary.nextPayment.total_cents)}{' '}
                including VAT.
              </CardDescription>
            )}
          </CardHeader>
          <CardContent className="grid gap-5">
            {summary.included ? (
              <Meter
                label="Monthly WURK Credits"
                used={summary.spentThisMonth ?? 0}
                included={summary.included}
                unit="credits"
              />
            ) : null}
            {(summary.meters ?? []).map((m) => (
              <Meter key={m.label} {...m} />
            ))}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>
              {num(summary.balance)} credits{value}
            </CardTitle>
            <CardDescription>
              {summary.allowance > 0 && summary.allowanceExpires
                ? `${num(summary.allowance)} monthly credits expire on ${day(summary.allowanceExpires)} — they’re used first. Bought credits never expire.`
                : 'Bought credits never expire. Monthly credits are used first.'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Button variant="secondary" size="sm" asChild>
              <a href={`${base}/statement.csv`}>
                <Download /> Download statement (CSV)
              </a>
            </Button>
          </CardContent>
        </Card>

        <CreditOverview summary={summary} />

        {summary.canManage && (
          <Card>
            <CardHeader>
              <CardTitle>Monthly spending limit</CardTitle>
              <CardDescription>
                Stops spending once this many credits are used in a month — including messages to
                customers. Leave empty for no limit.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  limit.transform((d) => ({ monthly: d.monthly === '' ? null : d.monthly }));
                  limit.put(`${base}/limits`, { preserveScroll: true });
                }}
                className="flex flex-col gap-3 sm:flex-row sm:items-end"
              >
                <Field
                  label="Limit (credits per month)"
                  error={limit.errors.monthly}
                  className="sm:w-56"
                >
                  <Input
                    type="number"
                    min={1}
                    value={limit.data.monthly}
                    onChange={(e) =>
                      limit.setData('monthly', e.target.value === '' ? '' : Number(e.target.value))
                    }
                  />
                </Field>
                <Button type="submit" loading={limit.processing}>
                  Save
                </Button>
              </form>
            </CardContent>
          </Card>
        )}

        <Card>
          <CardHeader>
            <CardTitle>Low-balance alert</CardTitle>
            <CardDescription>
              We’ll let you know when your balance drops below this, so you can buy more in time.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form
              onSubmit={(e) => {
                e.preventDefault();
                alert.put(`${base}/alert`, { preserveScroll: true });
              }}
              className="flex flex-col gap-3 sm:flex-row sm:items-end"
            >
              <Field
                label="Alert below (credits)"
                error={alert.errors.low_balance}
                className="sm:w-56"
              >
                <Input
                  type="number"
                  min={0}
                  value={alert.data.low_balance}
                  onChange={(e) => alert.setData('low_balance', Number(e.target.value))}
                />
              </Field>
              <Button type="submit" loading={alert.processing}>
                Save
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
