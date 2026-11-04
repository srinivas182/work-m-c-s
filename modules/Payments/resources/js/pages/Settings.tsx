import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Button, Field, Input, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { type Method, MethodCard } from '../components/MethodCard';

export default function PaymentSettings({
  methods,
  branches,
  refundDays,
}: {
  methods: Method[];
  branches: { id: string; name: string }[];
  refundDays: number;
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  const [branch, setBranch] = useState<string>('');
  return (
    <AppShell title="Payments">
      <Head title="Payments" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="Payments"
          description="How customers pay you — for orders, deposits and classes. The money goes straight to your own account; WURK takes nothing. Try each method in test mode first."
        />
        {branches.length > 1 && (
          <div className="grid gap-2">
            <label className="flex flex-wrap items-center gap-2 text-sm font-medium">
              Accounts for
              <select
                className="border-border bg-bg h-10 rounded-md border px-3"
                value={branch}
                onChange={(e) => setBranch(e.target.value)}
              >
                <option value="">The whole business</option>
                {branches.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name}
                  </option>
                ))}
              </select>
            </label>
            {branch && (
              <p className="text-fg-muted text-sm">
                A method this branch doesn’t have its own account for uses the business’s.
              </p>
            )}
          </div>
        )}
        {methods.map((m) => (
          <MethodCard key={`${m.provider}-${branch}`} m={m} base={base} branch={branch || null} />
        ))}
        <RefundWindowCard days={refundDays} base={base} />
      </div>
    </AppShell>
  );
}

/** Refunds and returns: WURK refuses refunds once this closes; referral rewards become available when it does. */
function RefundWindowCard({ days, base }: { days: number; base: string }) {
  const form = useForm<{ days: number | '' }>({ days });
  return (
    <section
      aria-labelledby="refunds-title"
      className="border-border grid gap-3 rounded-lg border p-4"
    >
      <h2 id="refunds-title" className="text-lg font-semibold">
        Refunds and returns
      </h2>
      <p className="text-fg-muted text-sm">
        How long after payment you accept refunds (for bookings: after the appointment). After this,
        refunds can’t be made in WURK, and referral rewards become available to spend. Use 0 for no
        refunds — rewards are then available straight away.
      </p>
      <form
        aria-label="Refunds and returns"
        className="flex flex-wrap items-end gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          form.put(`${base}/refunds`, { preserveScroll: true });
        }}
      >
        <Field label="Refunds accepted for (days)" error={form.errors.days}>
          <Input
            type="number"
            inputMode="numeric"
            min="0"
            max="365"
            value={form.data.days}
            onChange={(e) =>
              form.setData('days', e.target.value === '' ? '' : Number(e.target.value))
            }
          />
        </Field>
        <Button type="submit" loading={form.processing}>
          Save
        </Button>
      </form>
    </section>
  );
}
