import { Head, Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/design-system';

/** The agent area's own layout (no business around it). */
export function AgentLayout({ title, children }: { title: string; children: ReactNode }) {
  const status = (usePage().props.flash as { status?: string | null } | undefined)?.status;
  return (
    <div className="bg-bg text-fg min-h-screen">
      <Head title={`${title} — WURK agents`} />
      <header className="border-border flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
        <span className="font-semibold">WURK agents</span>
        <nav aria-label="Agent area" className="flex flex-wrap items-center gap-3 text-sm">
          <Link href="/agent">Home</Link>
          <Link href="/agent/businesses">My businesses</Link>
          <Link href="/agent/bank">Banking details</Link>
          <Button size="sm" variant="ghost" onClick={() => router.post('/sign-out')}>
            Sign out
          </Button>
        </nav>
      </header>
      <main className="mx-auto grid max-w-2xl gap-6 px-4 py-8">
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
