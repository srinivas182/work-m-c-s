import { Head } from '@inertiajs/react';
import { LayoutDashboard } from 'lucide-react';
import { EmptyState, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';

export default function AdminHome() {
  return (
    <AppShell title="WURK admin">
      <Head title="WURK admin" />
      <div className="grid gap-8">
        <PageHeader
          eyebrow="WURK staff"
          title="Admin"
          description="Packages, businesses and platform settings arrive in Sprint 2."
        />
        <EmptyState
          icon={LayoutDashboard}
          title="Signed in with two-step verification"
          description="This is the WURK staff back office. Its dashboard is built in the coming sprints."
        />
      </div>
    </AppShell>
  );
}
