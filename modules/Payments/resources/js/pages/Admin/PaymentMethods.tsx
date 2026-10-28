import { Head } from '@inertiajs/react';
import { PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { type Method, MethodCard } from '../../components/MethodCard';

export default function PaymentMethods({ methods }: { methods: Method[] }) {
  const base = window.location.pathname.replace(/\/$/, '');
  return (
    <AppShell title="Payment methods">
      <Head title="Payment methods" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="Payment methods"
          description="Which methods businesses may offer, and WURK’s own accounts for businesses paying WURK. Automatic monthly card charges and DebiCheck are set under Providers."
        />
        {methods.map((m) => (
          <MethodCard key={m.provider} m={m} base={base} admin />
        ))}
      </div>
    </AppShell>
  );
}
