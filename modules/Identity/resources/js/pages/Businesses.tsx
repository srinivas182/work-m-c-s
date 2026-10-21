import { Link, router } from '@inertiajs/react';
import { Building2, ChevronRight, LogOut } from 'lucide-react';
import { Button, EmptyState } from '@/design-system';
import AuthLayout from '@/layouts/AuthLayout';

interface Business {
  name: string;
  handle: string;
  url: string;
}

export default function Businesses({ businesses }: { businesses: Business[] }) {
  const signOut = (
    <button
      type="button"
      onClick={() => router.post('/sign-out')}
      className="text-accent-fg inline-flex cursor-pointer items-center gap-1.5 font-medium hover:underline"
    >
      <LogOut className="size-4" aria-hidden /> Sign out
    </button>
  );

  if (businesses.length === 0) {
    return (
      <AuthLayout title="No businesses yet" footer={signOut}>
        <EmptyState
          icon={Building2}
          title="You’re not part of a business on WURK"
          description="Ask the business owner to add you to their team, or create your own business."
          action={
            <Button asChild>
              <a href="https://wurk.co.za">Create a business</a>
            </Button>
          }
        />
      </AuthLayout>
    );
  }

  return (
    <AuthLayout
      title="Choose a business"
      description="You’re part of more than one business."
      footer={signOut}
    >
      <ul className="grid gap-3">
        {businesses.map((b) => (
          <li key={b.handle}>
            <Link
              href={b.url}
              className="group border-border bg-surface hover:border-brand-7 flex items-center gap-4 rounded-2xl border p-4 shadow-xs transition-all hover:shadow-md"
            >
              <span className="bg-brand-3 font-display text-brand-11 grid size-11 shrink-0 place-items-center rounded-xl text-lg font-bold">
                {b.name.charAt(0).toUpperCase()}
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">{b.name}</span>
                <span className="text-fg-muted block truncate text-sm">
                  app.wurk.co.za/{b.handle}
                </span>
              </span>
              <ChevronRight
                className="text-fg-muted size-5 transition-transform group-hover:translate-x-0.5"
                aria-hidden
              />
            </Link>
          </li>
        ))}
      </ul>
    </AuthLayout>
  );
}
