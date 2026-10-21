import { Head, Link } from '@inertiajs/react';
import { Rocket } from 'lucide-react';
import { Button, EmptyState, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';

export default function Workspace({ business }: { business: { name: string; handle: string } }) {
  return (
    <AppShell title={business.name}>
      <Head title={business.name} />
      <div className="grid gap-8">
        <PageHeader
          eyebrow="Business back office"
          title={`Welcome to ${business.name}`}
          description="Your dashboard arrives in the next sprints."
        />
        <EmptyState
          icon={Rocket}
          title="You’re signed in"
          description="Bookings, your shop, customers and reports will appear here as each module is delivered."
          action={
            <Button variant="secondary" asChild>
              <Link href="/account/security">Review account security</Link>
            </Button>
          }
        />
      </div>
    </AppShell>
  );
}
