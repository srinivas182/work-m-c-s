import { Head, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/design-system';

/** My WURK's own simple layout (not a business's, not the back office). */
export function MyWurkLayout({
  title,
  signedIn = false,
  children,
}: {
  title: string;
  signedIn?: boolean;
  children: ReactNode;
}) {
  const status = (usePage().props.flash as { status?: string | null } | undefined)?.status;
  return (
    <div className="bg-bg text-fg min-h-screen">
      <Head title={`${title} — My WURK`} />
      <header className="border-border flex items-center justify-between border-b px-4 py-3">
        <span className="font-semibold">My WURK</span>
        {signedIn && (
          <Button size="sm" variant="ghost" onClick={() => router.post('/sign-out')}>
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
