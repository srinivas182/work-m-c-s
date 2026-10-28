import { Head } from '@inertiajs/react';
import { PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { type Method, MethodCard } from '../components/MethodCard';

export default function PaymentSettings({ methods }: { methods: Method[] }) {
  const base = window.location.pathname.replace(/\/$/, '');
  return (
    <AppShell title="Payments">
      <Head title="Payments" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="Payments"
          description="How customers pay you — for orders, deposits and classes. The money goes straight to your own account; WURK takes nothing. Try each method in test mode first."
        />
        {methods.map((m) => (
          <MethodCard key={m.provider} m={m} base={base} />
        ))}
      </div>
    </AppShell>
  );
}
