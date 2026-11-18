import { Head } from '@inertiajs/react';
import { PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { type Method, MethodCard } from '../../components/MethodCard';
import { __ } from '@/i18n';

export default function PaymentMethods({ methods }: { methods: Method[] }) {
  const base = window.location.pathname.replace(/\/$/, '');
  return (
    <AppShell title={__('Payment methods')}>
      <Head title={__('Payment methods')} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title={__('Payment methods')}
          description={__(
            'Which methods businesses may offer, and WURK’s own accounts for businesses paying WURK. Automatic monthly card charges and DebiCheck are set under Providers.',
          )}
        />
        {methods.map((m) => (
          <MethodCard key={m.provider} m={m} base={base} admin />
        ))}
      </div>
    </AppShell>
  );
}
