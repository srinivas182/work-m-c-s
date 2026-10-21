import { Head, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import type { SharedProps } from '@/types/global';

interface AuthLayoutProps {
  title: string;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
}

/**
 * Sign-in and account recovery screens: a calm single card on phones, with a
 * brand panel alongside on larger screens.
 */
export default function AuthLayout({ title, description, children, footer }: AuthLayoutProps) {
  const { flash } = usePage<SharedProps>().props;

  return (
    <>
      <Head title={title} />
      <div className="grid min-h-dvh lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
        <aside className="bg-neutral-12 text-neutral-1 relative hidden overflow-hidden lg:flex lg:flex-col lg:justify-between lg:p-12">
          <div
            aria-hidden
            className="bg-brand-9/40 absolute -top-32 -left-24 size-[32rem] rounded-full blur-3xl"
          />
          <div
            aria-hidden
            className="bg-brand-7/30 absolute -right-24 -bottom-40 size-[28rem] rounded-full blur-3xl"
          />
          <div className="relative flex items-center gap-2.5">
            <span className="from-brand-8 to-brand-10 font-display text-primary-fg grid size-9 place-items-center rounded-lg bg-gradient-to-br text-base font-bold">
              W
            </span>
            <span className="font-display text-xl font-bold tracking-tight">WURK</span>
          </div>
          <div className="relative max-w-md">
            <p className="font-display text-display-sm font-bold text-balance">
              Everything your business needs, in one place.
            </p>
            <p className="mt-4 text-lg text-pretty opacity-75">
              Website, bookings, shop, customers and referrals — run it all from your phone.
            </p>
          </div>
          <p className="relative flex items-center gap-2 text-sm opacity-70">
            <ShieldCheck className="size-4" aria-hidden /> Your data is protected and stored in
            South Africa.
          </p>
        </aside>

        <main className="flex items-center justify-center px-5 py-10 sm:px-8">
          <div className="w-full max-w-md">
            <div className="mb-8 flex items-center gap-2.5 lg:hidden">
              <span className="from-brand-8 to-brand-10 font-display text-primary-fg grid size-9 place-items-center rounded-lg bg-gradient-to-br text-base font-bold">
                W
              </span>
              <span className="font-display text-xl font-bold tracking-tight">WURK</span>
            </div>
            {flash.status && (
              <div
                role="status"
                className="border-brand-6 bg-brand-3 text-brand-11 mb-6 rounded-xl border px-4 py-3 text-sm"
              >
                {flash.status}
              </div>
            )}
            <h1 className="text-3xl font-semibold tracking-tight text-balance">{title}</h1>
            {description && <p className="text-fg-muted mt-2 text-pretty">{description}</p>}
            <div className="mt-8">{children}</div>
            {footer && <div className="text-fg-muted mt-8 text-sm">{footer}</div>}
          </div>
        </main>
      </div>
    </>
  );
}
