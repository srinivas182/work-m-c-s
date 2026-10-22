import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Textarea,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface Cash {
  id: string;
  at: string;
  payer: string;
  receipt: string;
  status: 'awaiting_approval' | 'approved' | 'cancelled';
  total_cents: number | null;
  purpose: string | null;
}

interface Props {
  business: { id: string; name: string };
  subscription: { package: string; period: string; period_cents: number; status: string } | null;
  packs: { id: string; name: string; credits: number; price_cents: number }[];
  canRecord: boolean;
  cash: Cash[];
}

function Approve({ businessId, item }: { businessId: string; item: Cash }) {
  const form = useForm({ code: '' });
  const base = `/businesses/${businessId}/cash/${item.id}`;
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        form.post(`${base}/approve`, { preserveScroll: true });
      }}
      className="flex flex-wrap items-end gap-3"
    >
      <Field label="Approval code" error={form.errors.code} className="w-40">
        <Input
          value={form.data.code}
          onChange={(e) => form.setData('code', e.target.value)}
          inputMode="numeric"
          autoComplete="one-time-code"
        />
      </Field>
      <Button type="submit" loading={form.processing}>
        Confirm payment
      </Button>
      <Button
        type="button"
        variant="ghost"
        onClick={() => router.post(`${base}/resend`, {}, { preserveScroll: true })}
      >
        Send new code
      </Button>
      <Button
        type="button"
        variant="ghost"
        onClick={() => router.post(`${base}/cancel`, {}, { preserveScroll: true })}
      >
        Cancel
      </Button>
    </form>
  );
}

export default function CashPayments({ business, subscription, packs, canRecord, cash }: Props) {
  const form = useForm({
    purpose: 'subscription',
    pack_id: packs[0]?.id ?? '',
    payer_name: '',
    receipt_number: '',
    notes: '',
  });
  return (
    <AppShell title={`${business.name} — cash`}>
      <Head title={`${business.name} — cash`} />
      <div className="grid max-w-4xl gap-8">
        <Link
          href={`/businesses/${business.id}`}
          className="text-fg-muted hover:text-fg inline-flex w-fit items-center gap-1.5 text-sm font-medium"
        >
          <ArrowLeft className="size-4" aria-hidden /> {business.name}
        </Link>
        <PageHeader
          title="Cash payments"
          eyebrow={business.name}
          description="Record cash paid at a WURK office. It counts only once another approver confirms it with the code we send them."
        />
        <Card>
          <CardHeader>
            <CardTitle>Record cash</CardTitle>
            <CardDescription>
              {subscription
                ? `${subscription.package}, ${subscription.period}: ${rand(subscription.period_cents)} excl. VAT.`
                : 'This business has no package yet.'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {!canRecord ? (
              <p role="alert" className="text-danger-11 text-sm">
                Add an approver other than yourself in{' '}
                <Link href="/security" className="font-semibold underline underline-offset-4">
                  Settings → Security
                </Link>{' '}
                before recording cash.
              </p>
            ) : (
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  form.post(`/businesses/${business.id}/cash`, {
                    preserveScroll: true,
                    onSuccess: () => form.reset('payer_name', 'receipt_number', 'notes'),
                  });
                }}
                className="grid gap-5 sm:grid-cols-2"
                noValidate
              >
                <Field label="Paying for" error={form.errors.purpose}>
                  {(control) => (
                    <Select
                      value={form.data.purpose}
                      onValueChange={(v) => form.setData('purpose', v)}
                    >
                      <SelectTrigger {...control}>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="subscription">Subscription</SelectItem>
                        {packs.length > 0 && <SelectItem value="credits">WURK Credits</SelectItem>}
                      </SelectContent>
                    </Select>
                  )}
                </Field>
                {form.data.purpose === 'credits' && (
                  <Field label="Credit pack" error={form.errors.pack_id}>
                    {(control) => (
                      <Select
                        value={form.data.pack_id}
                        onValueChange={(v) => form.setData('pack_id', v)}
                      >
                        <SelectTrigger {...control}>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {packs.map((p) => (
                            <SelectItem
                              key={p.id}
                              value={p.id}
                            >{`${p.name} — ${p.credits} credits, ${rand(p.price_cents)} excl. VAT`}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    )}
                  </Field>
                )}
                <Field label="Paid by" error={form.errors.payer_name} required>
                  <Input
                    value={form.data.payer_name}
                    onChange={(e) => form.setData('payer_name', e.target.value)}
                  />
                </Field>
                <Field label="Receipt book number" error={form.errors.receipt_number} required>
                  <Input
                    value={form.data.receipt_number}
                    onChange={(e) => form.setData('receipt_number', e.target.value)}
                  />
                </Field>
                <Field label="Notes" error={form.errors.notes} className="sm:col-span-2">
                  <Textarea
                    rows={2}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                  />
                </Field>
                {(form.errors as Record<string, string>).cash && (
                  <p role="alert" className="text-danger-11 text-sm sm:col-span-2">
                    {(form.errors as Record<string, string>).cash}
                  </p>
                )}
                <Button type="submit" className="w-fit" loading={form.processing}>
                  Record and send approval code
                </Button>
              </form>
            )}
          </CardContent>
        </Card>
        {cash.map((c) => (
          <Card key={c.id}>
            <CardContent className="grid gap-4 p-6">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <p>
                  <span className="font-semibold">
                    {c.total_cents !== null ? rand(c.total_cents) : ''}
                  </span>{' '}
                  <span className="text-fg-muted">
                    · {c.purpose === 'credits' ? 'WURK Credits' : 'Subscription'} · paid by{' '}
                    {c.payer} · receipt {c.receipt} · {new Date(c.at).toLocaleString()}
                  </span>
                </p>
                <Badge
                  tone={
                    c.status === 'approved'
                      ? 'success'
                      : c.status === 'cancelled'
                        ? 'neutral'
                        : 'warning'
                  }
                >
                  {c.status === 'awaiting_approval'
                    ? 'Waiting for approval'
                    : c.status === 'approved'
                      ? 'Confirmed'
                      : 'Cancelled'}
                </Badge>
              </div>
              {c.status === 'awaiting_approval' && <Approve businessId={business.id} item={c} />}
            </CardContent>
          </Card>
        ))}
      </div>
    </AppShell>
  );
}
