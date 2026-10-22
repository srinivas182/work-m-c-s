import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Dialog,
  DialogContent,
  DialogFooter,
  Field,
  Input,
  PageHeader,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface InvoiceRow {
  id: string;
  number: string;
  issued_at: string;
  total_cents: number;
  credited_cents: number;
  credit_notes: {
    id: string;
    number: string;
    total_cents: number;
    refund: string;
    refund_status: string;
  }[];
}

interface Props {
  business: { id: string; name: string };
  subscription: { package: string; period: string; status: string } | null;
  stage: string;
  day: number | null;
  canManage: boolean;
  invoices: InvoiceRow[];
  payments: {
    id: string;
    at: string;
    purpose: string;
    gateway: string;
    total_cents: number;
    status: string;
    failure: string | null;
  }[];
}

const STAGE: Record<string, string> = {
  none: 'Paid up',
  grace: 'Grace period',
  restricted: 'Billing only',
  suspended: 'Suspended',
};

function CreditDialog({
  business,
  invoice,
  onClose,
}: {
  business: Props['business'];
  invoice: InvoiceRow;
  onClose: () => void;
}) {
  const form = useForm({ amount: '', reason: '' });
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent
        title={`Credit note for ${invoice.number}`}
        description={`Up to ${rand(invoice.total_cents - invoice.credited_cents)} incl. VAT can still be credited. Card payments are refunded automatically; cash and debit orders by EFT.`}
      >
        <form
          onSubmit={(e) => {
            e.preventDefault();
            form.post(`/businesses/${business.id}/invoices/${invoice.id}/credit`, {
              preserveScroll: true,
              onSuccess: onClose,
            });
          }}
          className="grid gap-4"
        >
          <Field
            label="Amount excl. VAT (R)"
            hint="Leave empty to credit everything that’s left"
            error={form.errors.amount}
          >
            <Input
              type="number"
              step="0.01"
              value={form.data.amount}
              onChange={(e) => form.setData('amount', e.target.value)}
            />
          </Field>
          <Field label="Reason (printed on the credit note)" error={form.errors.reason} required>
            <Input
              value={form.data.reason}
              onChange={(e) => form.setData('reason', e.target.value)}
            />
          </Field>
          <DialogFooter>
            <Button type="submit" loading={form.processing}>
              Issue credit note
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export default function BusinessBilling({
  business,
  subscription,
  stage,
  day,
  canManage,
  invoices,
  payments,
}: Props) {
  const [crediting, setCrediting] = useState<InvoiceRow | null>(null);
  return (
    <AppShell title={`${business.name} — billing`}>
      <Head title={`${business.name} — billing`} />
      <div className="grid max-w-5xl gap-8">
        <Link
          href={`/businesses/${business.id}`}
          className="text-fg-muted hover:text-fg inline-flex w-fit items-center gap-1.5 text-sm font-medium"
        >
          <ArrowLeft className="size-4" aria-hidden /> {business.name}
        </Link>
        <PageHeader
          title="Invoices and payments"
          eyebrow={business.name}
          actions={
            <Badge
              tone={stage === 'none' ? 'success' : stage === 'grace' ? 'warning' : 'danger'}
              dot
            >
              {STAGE[stage] ?? stage}
              {day !== null ? ` · day ${day}` : ''}
            </Badge>
          }
        />
        {subscription && (
          <Card>
            <CardHeader>
              <div className="flex flex-wrap items-center justify-between gap-3">
                <CardTitle>
                  {subscription.package}, {subscription.period}
                </CardTitle>
                {canManage && ['past_due', 'suspended'].includes(subscription.status) && (
                  <Button
                    onClick={() =>
                      router.post(
                        `/businesses/${business.id}/billing/retry`,
                        {},
                        { preserveScroll: true },
                      )
                    }
                  >
                    Retry payment now
                  </Button>
                )}
              </div>
            </CardHeader>
          </Card>
        )}
        <Table label="Tax invoices">
          <TableHeader>
            <TableRow>
              <TableHead>Invoice</TableHead>
              <TableHead>Date</TableHead>
              <TableHead className="text-right">Total</TableHead>
              <TableHead>Credit notes</TableHead>
              <TableHead>
                <span className="sr-only">Actions</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {invoices.length === 0 && (
              <TableRow>
                <TableCell colSpan={5} className="text-fg-muted">
                  No invoices yet.
                </TableCell>
              </TableRow>
            )}
            {invoices.map((i) => (
              <TableRow key={i.id}>
                <TableCell className="font-medium">
                  <a
                    href={`/businesses/${business.id}/invoices/${i.id}`}
                    className="underline-offset-4 hover:underline"
                  >
                    {i.number}
                  </a>
                </TableCell>
                <TableCell className="text-fg-muted">
                  {new Date(i.issued_at).toLocaleDateString()}
                </TableCell>
                <TableCell className="text-right tabular-nums">{rand(i.total_cents)}</TableCell>
                <TableCell>
                  <div className="grid gap-1">
                    {i.credit_notes.map((n) => (
                      <span key={n.id} className="flex flex-wrap items-center gap-2 text-sm">
                        {n.number} · {rand(n.total_cents)}
                        <Badge
                          tone={
                            n.refund_status === 'done' || n.refund_status === 'not_needed'
                              ? 'success'
                              : 'warning'
                          }
                        >
                          {n.refund === 'eft'
                            ? n.refund_status === 'done'
                              ? 'EFT paid'
                              : 'EFT refund due'
                            : n.refund_status === 'done'
                              ? 'Refunded to card'
                              : n.refund === 'none'
                                ? 'No refund'
                                : 'Refund pending'}
                        </Badge>
                        {canManage && n.refund === 'eft' && n.refund_status === 'pending' && (
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                              router.post(
                                `/businesses/${business.id}/credit-notes/${n.id}/refunded`,
                                {},
                                { preserveScroll: true },
                              )
                            }
                          >
                            Mark EFT paid
                          </Button>
                        )}
                      </span>
                    ))}
                  </div>
                </TableCell>
                <TableCell className="text-right">
                  {canManage && i.credited_cents < i.total_cents && (
                    <Button size="sm" variant="secondary" onClick={() => setCrediting(i)}>
                      Credit
                    </Button>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
        <Card>
          <CardHeader>
            <CardTitle>Payments</CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="divide-border divide-y">
              {payments.map((p) => (
                <li
                  key={p.id}
                  className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
                >
                  <span>
                    {new Date(p.at).toLocaleString()} · {p.purpose} · {p.gateway}
                    {p.failure ? ` · ${p.failure}` : ''}
                  </span>
                  <span className="flex items-center gap-2">
                    <span className="tabular-nums">{rand(p.total_cents)}</span>
                    <Badge
                      tone={
                        p.status === 'succeeded'
                          ? 'success'
                          : p.status === 'failed'
                            ? 'danger'
                            : 'neutral'
                      }
                    >
                      {p.status}
                    </Badge>
                  </span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      </div>
      {crediting && (
        <CreditDialog business={business} invoice={crediting} onClose={() => setCrediting(null)} />
      )}
    </AppShell>
  );
}
