import { Head, router, usePage } from '@inertiajs/react';
import { Gift } from 'lucide-react';
import { useState } from 'react';
import { Badge, Button, EmptyState, Field, Input, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface Payout {
  id: string;
  customer: string;
  cents: number;
  status: 'requested' | 'paid' | 'declined';
  asked: string;
  holder: string;
  bank: string;
  branch: string | null;
  account: string | null;
  reference: string | null;
  reason: string | null;
  decided: string | null;
}

/** Customers → Payouts: pay from your own bank, then mark paid (or decline — the amount goes back to their wallet). */
export default function Payouts({ payouts }: { payouts: Payout[] }) {
  const base = window.location.pathname.replace(/\/$/, '');
  const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
  return (
    <AppShell title="Payouts">
      <Head title="Payouts" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="Payouts"
          description="Customers asking for their rewards to be paid out. Pay them from your bank, then mark it paid with your reference."
        />
        {errors.payout && (
          <p role="alert" className="text-danger text-sm">
            {errors.payout}
          </p>
        )}
        {payouts.length === 0 ? (
          <EmptyState
            icon={Gift}
            title="No payout requests"
            description="When a customer asks for their rewards to be paid out, it appears here."
          />
        ) : (
          <ul aria-label="Payout requests" className="grid gap-3">
            {payouts.map((p) => (
              <PayoutRow key={p.id} p={p} base={base} />
            ))}
          </ul>
        )}
      </div>
    </AppShell>
  );
}

function PayoutRow({ p, base }: { p: Payout; base: string }) {
  const [reference, setReference] = useState('');
  const [reason, setReason] = useState('');
  const [declining, setDeclining] = useState(false);
  const post = (path: string, data: Record<string, string>) =>
    router.post(`${base}/${p.id}/${path}`, data, { preserveScroll: true });
  return (
    <li className="border-border grid gap-2 rounded-lg border p-4 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <strong>{p.customer}</strong> {rand(p.cents)} · asked {p.asked}
        {p.status === 'requested' && <Badge tone="warning">To pay</Badge>}
        {p.status === 'paid' && <Badge tone="success">Paid</Badge>}
        {p.status === 'declined' && <Badge tone="neutral">Declined</Badge>}
      </div>
      <p>
        {p.holder} · {p.bank} · <span className="font-mono">{p.account}</span>
        {p.branch ? ` · branch ${p.branch}` : ''}
      </p>
      {p.decided && (
        <p className="text-fg-muted">
          {p.decided}
          {p.reference ? ` · reference ${p.reference}` : ''}
          {p.reason ? ` — ${p.reason}` : ''}
        </p>
      )}
      {p.status === 'requested' && (
        <div className="grid gap-2">
          <form
            aria-label={`Mark ${p.customer}’s payout paid`}
            className="flex flex-wrap items-end gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              post('paid', { reference });
            }}
          >
            <Field label="Your payment reference">
              <Input
                value={reference}
                maxLength={80}
                onChange={(e) => setReference(e.target.value)}
              />
            </Field>
            <Button size="sm" type="submit">
              Mark paid
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setDeclining(true)}>
              Decline…
            </Button>
          </form>
          {declining && (
            <form
              aria-label={`Decline ${p.customer}’s payout`}
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
        </div>
      )}
    </li>
  );
}
