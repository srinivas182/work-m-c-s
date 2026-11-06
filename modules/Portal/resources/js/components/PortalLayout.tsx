import { Head, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/design-system';

/** The customer portal's own simple layout (not the business back office). */
export function PortalLayout({
  business,
  title,
  signedIn = false,
  children,
}: {
  business: string;
  title: string;
  signedIn?: boolean;
  children: ReactNode;
}) {
  const status = (usePage().props.flash as { status?: string | null } | undefined)?.status;
  return (
    <div className="bg-bg text-fg min-h-screen">
      <Head title={`${title} — ${business}`} />
      <header className="border-border flex items-center justify-between border-b px-4 py-3">
        <a href="/" className="font-semibold">
          {business}
        </a>
        {signedIn && (
          <Button size="sm" variant="ghost" onClick={() => router.post('/account/sign-out')}>
            Sign out
          </Button>
        )}
      </header>
      <main className="mx-auto grid max-w-xl gap-6 px-4 py-8">
        <h1 className="text-2xl font-semibold">{title}</h1>
        {status && (
          <p role="status" className="text-sm">
            {status}
          </p>
        )}
        {children}
      </main>
    </div>
  );
}
