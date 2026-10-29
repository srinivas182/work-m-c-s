import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { type Method, MethodCard } from '../components/MethodCard';

export default function PaymentSettings({
  methods,
  branches,
}: {
  methods: Method[];
  branches: { id: string; name: string }[];
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
      </div>
    </AppShell>
  );
}
