import { Head, router, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { Badge, Button, EmptyState, Field, Input, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface Row {
  id: string;
  customer: string;
  status: 'open' | 'completed' | 'declined';
  reason: string | null;
  asked: string;
  days_open: number | null;
  wallet_cents: number;
  pending_cents: number;
  decided: string | null;
}

/** Customers → Data requests: POPIA deletion requests from the portal. */
export default function DataRequests({ requests }: { requests: Row[] }) {
  const base = window.location.pathname.replace(/\/$/, '');
  const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
  return (
    <AppShell title="Data requests">
      <Head title="Data requests" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="Data requests"
          description="Customers asking you to delete their information (POPIA). Pay out their wallet first; records the law requires (such as invoices) are kept."
        />
        {errors.request && (
          <p role="alert" className="text-danger text-sm">
            {errors.request}
          </p>
        )}
        {requests.length === 0 ? (
          <EmptyState
            icon={ShieldCheck}
            title="No requests"
            description="When a customer asks you to delete their information from their account, it appears here."
          />
        ) : (
          <ul aria-label="Data requests" className="grid gap-3">
            {requests.map((r) => (
              <RequestRow key={r.id} r={r} base={base} />
            ))}
          </ul>
        )}
      </div>
    </AppShell>
  );
}

function RequestRow({ r, base }: { r: Row; base: string }) {
  const [settle, setSettle] = useState({
    amount: String(r.wallet_cents / 100),
    method: 'eft',
    reference: '',
  });
  const [reason, setReason] = useState('');
  const [declining, setDeclining] = useState(false);
  const post = (path: string, data: Record<string, string>) =>
    router.post(`${base}/${r.id}/${path}`, data, { preserveScroll: true });
  return (
    <li className="border-border grid gap-3 rounded-lg border p-4 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <strong>{r.customer}</strong> asked to be deleted on {r.asked}
        {r.status === 'open' && (
          <Badge tone={r.days_open !== null && r.days_open > 7 ? 'warning' : 'neutral'}>
            Open {r.days_open} days
          </Badge>
        )}
        {r.status === 'completed' && <Badge tone="success">Deleted</Badge>}
        {r.status === 'declined' && <Badge tone="neutral">Declined</Badge>}
      </div>
      {r.decided && (
        <p className="text-fg-muted">
          {r.decided}
          {r.reason ? ` — ${r.reason}` : ''}
        </p>
      )}
      {r.status === 'open' && (
        <>
          {r.pending_cents > 0 && (
            <p className="text-fg-muted">
              {rand(r.pending_cents)} in rewards not yet available will be cancelled.
            </p>
          )}
          {r.wallet_cents > 0 ? (
            <form
              aria-label={`Pay out ${r.customer}’s wallet`}
              className="grid gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                post('settle', settle);
              }}
            >
              <p>First pay out or refund their wallet: {rand(r.wallet_cents)}.</p>
              <div className="flex flex-wrap items-end gap-2">
                <Field label="Amount paid (R)">
                  <Input
                    type="number"
                    inputMode="decimal"
                    min="0.01"
                    step="0.01"
                    value={settle.amount}
                    onChange={(e) => setSettle({ ...settle, amount: e.target.value })}
                  />
                </Field>
                <Field label="How">
                  <select
                    className="border-border bg-bg h-10 rounded-md border px-3"
                    value={settle.method}
                    onChange={(e) => setSettle({ ...settle, method: e.target.value })}
                  >
                    <option value="eft">EFT</option>
                    <option value="cash">Cash</option>
                  </select>
                </Field>
                <Field label="Reference (optional)">
                  <Input
                    value={settle.reference}
                    maxLength={80}
                    onChange={(e) => setSettle({ ...settle, reference: e.target.value })}
                  />
                </Field>
                <Button size="sm" type="submit">
                  Record payout
                </Button>
              </div>
            </form>
          ) : (
            <div className="flex flex-wrap gap-2">
              <Button size="sm" onClick={() => post('approve', {})}>
                Delete their information
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setDeclining(true)}>
                Decline…
              </Button>
            </div>
          )}
          {declining && (
            <form
              aria-label={`Decline ${r.customer}’s request`}
              className="flex flex-wrap items-end gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                post('decline', { reason });
              }}
            >
              <Field label="Why (sent to the customer)">
                <Input value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} />
              </Field>
              <Button size="sm" type="submit">
                Decline
              </Button>
            </form>
          )}
        </>
      )}
    </li>
  );
}
