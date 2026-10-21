import { Head } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { EmptyState } from '@/design-system';
import AppShell from '@/layouts/AppShell';

export default function NotIncluded({ module }: { module: string }) {
  return (
    <AppShell title={module}>
      <Head title={module} />
      <EmptyState
        icon={Sparkles}
        title={`${module} isn’t in your package`}
        description="Upgrade your package to start using it. Subscription management arrives in the next release."
      />
    </AppShell>
  );
}
