import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface Pkg {
  id: string;
  name: string;
  paying: number;
  mrr_cents: number;
  monthly_cents: number;
}
interface Props {
  canEdit: boolean;
  channels: Record<string, string>;
  provider: Record<string, number | null>;
  running: number | null;
  runningByPackage: Record<string, number>;
  packages: Pkg[];
}

const toRand = (c: number | null | undefined) =>
  c === null || c === undefined ? '' : (c / 100).toFixed(2);

/** Admin → Profitability: what things cost WURK (so profit is real), and a pricing "what if". */
export default function Profitability(p: Props) {
  const form = useForm({
    provider: Object.fromEntries(
      Object.keys(p.channels).map((ch) => [ch, toRand(p.provider[ch])]),
    ) as Record<string, string>,
    running: toRand(p.running),
    running_by_package: Object.fromEntries(
      p.packages.map((k) => [k.id, toRand(p.runningByPackage[k.id])]),
    ) as Record<string, string>,
  });
  const [prices, setPrices] = useState<Record<string, string>>(
    Object.fromEntries(p.packages.map((k) => [k.id, toRand(k.monthly_cents)])),
  );
  return (
    <AppShell title="Profitability">
      <Head title="Profitability" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Profitability"
          description="What things cost WURK — profit in Metrics uses these. Leave a field empty if you don’t know it yet: it’s reported as not set, never guessed."
        />
        <Card>
          <CardHeader>
            <CardTitle>Costs</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              aria-label="Costs"
              className="grid gap-3 text-sm sm:grid-cols-2"
              onSubmit={(e) => {
                e.preventDefault();
                form.put(`${window.location.pathname}/costs`, { preserveScroll: true });
              }}
            >
              {Object.entries(p.channels).map(([ch, label]) => (
                <Field
                  key={ch}
                  label={`${label}: provider cost per 1 000 messages (R)`}
                  error={(form.errors as Record<string, string>)[`provider.${ch}`]}
                >
                  <Input
                    inputMode="decimal"
                    disabled={!p.canEdit}
                    value={form.data.provider[ch]}
                    onChange={(e) =>
                      form.setData('provider', { ...form.data.provider, [ch]: e.target.value })
                    }
                  />
                </Field>
              ))}
              <Field
                label="Running cost per business per month (R) — hosting, storage, support"
                error={form.errors.running}
              >
                <Input
                  inputMode="decimal"
                  disabled={!p.canEdit}
                  value={form.data.running}
                  onChange={(e) => form.setData('running', e.target.value)}
                />
              </Field>
              {p.packages.map((k) => (
                <Field key={k.id} label={`${k.name}: running cost per month if different (R)`}>
                  <Input
                    inputMode="decimal"
                    disabled={!p.canEdit}
                    value={form.data.running_by_package[k.id] ?? ''}
                    onChange={(e) =>
                      form.setData('running_by_package', {
                        ...form.data.running_by_package,
                        [k.id]: e.target.value,
                      })
                    }
                  />
                </Field>
              ))}
              {p.canEdit && (
                <Button type="submit" className="justify-self-start" loading={form.processing}>
                  Save costs
                </Button>
              )}
            </form>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Pricing “what if”</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3 text-sm">
            <p className="text-fg-muted">
              Change a monthly price to see MRR if today’s paying businesses stayed (yearly plans
              scale with it). Nothing is changed.
            </p>
            <div className="overflow-x-auto">
              <table className="w-full text-left">
                <thead>
                  <tr>
                    <th scope="col">Package</th>
                    <th scope="col">Paying</th>
                    <th scope="col">MRR now</th>
                    <th scope="col">Monthly price (R)</th>
                    <th scope="col">MRR at that price</th>
                    <th scope="col">Difference</th>
                  </tr>
                </thead>
                <tbody>
                  {p.packages.map((k) => {
                    const price = Math.round(parseFloat(prices[k.id] || '0') * 100) || 0;
                    const projected =
                      k.monthly_cents > 0
                        ? Math.round((k.mrr_cents * price) / k.monthly_cents)
                        : k.paying * price;
                    const diff = projected - k.mrr_cents;
                    return (
                      <tr key={k.id}>
                        <th scope="row">{k.name}</th>
                        <td>{k.paying}</td>
                        <td>{rand(k.mrr_cents)}</td>
                        <td>
                          <Input
                            aria-label={`${k.name} monthly price`}
                            inputMode="decimal"
                            value={prices[k.id]}
                            onChange={(e) => setPrices({ ...prices, [k.id]: e.target.value })}
                          />
                        </td>
                        <td>{rand(projected)}</td>
                        <td>{(diff >= 0 ? '+' : '') + rand(diff)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
