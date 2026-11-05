import { Head, Link, router } from '@inertiajs/react';
import { Gift } from 'lucide-react';
import { useState } from 'react';
import { Button, EmptyState, Input, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface Member {
  id: string;
  name: string;
  code: string;
  link: string;
  referred_by: string | null;
  referred: number;
  pending_cents: number;
  available_cents: number;
}
interface Props {
  enabled: boolean;
  code: string;
  members: Member[];
  pages: { current: number; last: number; total: number };
}

export default function ReferralMembers({ enabled, code, members, pages }: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  const [search, setSearch] = useState(code);
  const [copied, setCopied] = useState<string | null>(null);
  const copy = (m: Member) => {
    void navigator.clipboard?.writeText(m.link).then(() => setCopied(m.id));
  };
  return (
    <AppShell title="Referrals">
      <Head title="Referrals" />
      <div className="grid max-w-5xl gap-6">
        <PageHeader
          title="Referrals"
          description="Each customer’s referral link and QR code, who referred them, and how many people they’ve brought."
        />
        {!enabled && (
          <p role="note" className="border-border rounded-lg border p-3 text-sm">
            Your referral programme is off — links don’t credit anyone while it’s off.{' '}
            <Link
              className="underline"
              href={`${base.replace(/\/referrals$/, '')}/settings/referrals`}
            >
              Set it up in Settings → Referrals
            </Link>
            .
          </p>
        )}
        <form
          role="search"
          aria-label="Find a customer by code"
          className="flex max-w-sm gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            router.get(base, search ? { code: search } : {}, { preserveState: true });
          }}
        >
          <Input
            aria-label="Referral code"
            placeholder="Referral code"
            value={search}
            maxLength={7}
            onChange={(e) => setSearch(e.target.value)}
          />
          <Button type="submit" variant="secondary">
            Find
          </Button>
        </form>
        {members.length === 0 ? (
          <EmptyState
            icon={Gift}
            title={code ? 'No customer with that code' : 'No customers in the programme yet'}
            description="Customers get a referral code once the programme is on and they book, order or get in touch."
          />
        ) : (
          <ul aria-label="Customers in the referral programme" className="grid gap-3">
            {members.map((m) => (
              <li
                key={m.id}
                className="border-border grid gap-2 rounded-lg border p-3 text-sm sm:grid-cols-[1fr_auto] sm:items-center"
              >
                <div className="grid gap-1">
                  <strong>{m.name}</strong>
                  <span>
                    Code <span className="font-mono">{m.code}</span> ·{' '}
                    {m.referred === 1 ? '1 person referred' : `${m.referred} people referred`}
                    {m.referred_by ? ` · referred by ${m.referred_by}` : ''}
                  </span>
                  <span>
                    Wallet: {rand(m.available_cents)} · {rand(m.pending_cents)} pending
                  </span>
                  <span className="text-fg-muted break-all">{m.link}</span>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button
                    size="sm"
                    variant="secondary"
                    aria-label={`Copy ${m.name}’s link`}
                    onClick={() => copy(m)}
                  >
                    {copied === m.id ? 'Copied' : 'Copy link'}
                  </Button>
                  <Button size="sm" variant="ghost" asChild>
                    <a
                      href={`${base}/${m.id}/qr.svg`}
                      download
                      aria-label={`Download ${m.name}’s QR code`}
                    >
                      QR code
                    </a>
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
        {pages.last > 1 && (
          <nav aria-label="Pages" className="flex items-center gap-3 text-sm">
            {pages.current > 1 && (
              <Link
                className="underline"
                href={`${base}?page=${pages.current - 1}${code ? `&code=${code}` : ''}`}
              >
                Previous
              </Link>
            )}
            <span>
              Page {pages.current} of {pages.last}
            </span>
            {pages.current < pages.last && (
              <Link
                className="underline"
                href={`${base}?page=${pages.current + 1}${code ? `&code=${code}` : ''}`}
              >
                Next
              </Link>
            )}
          </nav>
        )}
      </div>
    </AppShell>
  );
}
