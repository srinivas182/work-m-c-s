import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/design-system';
export { rand } from '@/lib/money';

export interface SiteProps {
  signupUrl: string;
  signInUrl: string;
  moduleNames: Record<string, string>;
}

const NAV = [
  { href: '/pricing', label: 'Pricing' },
  { href: '/faq', label: 'Questions' },
  { href: '/contact', label: 'Contact' },
];

/** Shell for wurk.co.za: plain header, generous content, quiet footer. */
export function Site({
  title,
  description,
  children,
  signupUrl,
  signInUrl,
}: SiteProps & { title?: string; description: string; children: ReactNode }) {
  return (
    <div className="min-h-dvh bg-[#F7F7FB] text-[#1B1640]">
      <Head title={title}>
        <meta name="description" content={description} />
        <meta property="og:title" content={title ? `${title} · WURK` : 'WURK'} />
        <meta property="og:description" content={description} />
      </Head>
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2"
      >
        Skip to content
      </a>
      <header className="mx-auto flex max-w-6xl items-center justify-between gap-6 px-5 py-5 sm:px-8">
        <Link
          href="/"
          className="font-display flex items-center gap-2.5 text-xl font-bold tracking-tight"
        >
          <span
            aria-hidden
            className="bg-primary text-primary-fg grid size-9 place-items-center rounded-[10px] text-base"
          >
            W
          </span>
          WURK
        </Link>
        <nav
          aria-label="Main"
          className="hidden items-center gap-7 text-[15px] font-medium md:flex"
        >
          {NAV.map((n) => (
            <Link key={n.href} href={n.href} className="text-[#1B1640]/75 hover:text-[#1B1640]">
              {n.label}
            </Link>
          ))}
          <a href={signInUrl} className="text-[#1B1640]/75 hover:text-[#1B1640]">
            Sign in
          </a>
        </nav>
        <Button asChild>
          <a href={signupUrl}>Start free trial</a>
        </Button>
      </header>
      <main id="main">{children}</main>
      <footer className="mt-24 border-t border-[#1B1640]/10">
        <div className="mx-auto grid max-w-6xl gap-8 px-5 py-12 text-sm text-[#1B1640]/70 sm:grid-cols-[2fr_1fr_1fr] sm:px-8">
          <div>
            <p className="font-display text-base font-bold text-[#1B1640]">WURK</p>
            <p className="mt-2 max-w-xs">
              Websites, bookings, a shop and WhatsApp reminders for South African businesses. Hosted
              in South Africa.
            </p>
          </div>
          <nav aria-label="Product" className="grid gap-2">
            <Link href="/pricing" className="hover:text-[#1B1640]">
              Pricing
            </Link>
            <Link href="/faq" className="hover:text-[#1B1640]">
              Questions
            </Link>
            <Link href="/contact" className="hover:text-[#1B1640]">
              Contact
            </Link>
          </nav>
          <nav aria-label="Legal" className="grid gap-2">
            <Link href="/legal/terms" className="hover:text-[#1B1640]">
              Terms of service
            </Link>
            <Link href="/legal/privacy" className="hover:text-[#1B1640]">
              Privacy (POPIA)
            </Link>
          </nav>
        </div>
      </footer>
    </div>
  );
}

export function vatInclusive(cents: number, vatPercent = 15): number {
  return Math.round(cents * (1 + vatPercent / 100));
}
