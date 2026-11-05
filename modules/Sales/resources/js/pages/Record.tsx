import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface SaleRow {
  id: string;
  customer: string;
  amount_cents: number;
  method: string;
  status: 'recorded' | 'pending' | 'declined';
  by: string;
  refunded_cents: number;
  at: string;
  note: string | null;
}
interface Props {
  branches: { id: string; name: string }[];
  methods: Record<string, string>;
  threshold: number;
  canApprove: boolean;
  canManage: boolean;
  sales: SaleRow[];
}

export default function RecordSale({
  branches,
  methods,
  threshold,
  canApprove,
  canManage,
  sales,
}: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
  const form = useForm({
    name: '',
    phone: '',
    email: '',
    amount: '',
    method: 'cash',
    note: '',
    branch_id: branches[0]?.id ?? '',
  });
  const [found, setFound] = useState<{ name: string; email: boolean } | null | 'new'>(null);
  const [refund, setRefund] = useState<{ id: string; amount: string } | null>(null);
  const find = async () => {
    const q = new URLSearchParams(
      form.data.phone ? { phone: form.data.phone } : { email: form.data.email },
    );
    const r = await fetch(`${base}/find?${q}`, { headers: { Accept: 'application/json' } });
    const c = r.ok
      ? ((await r.json()) as { customer: { name: string; email: boolean } | null }).customer
      : null;
    setFound(c ?? 'new');
    if (c) form.setData('name', c.name);
  };
  const act = (id: string, what: 'approve' | 'decline') =>
    router.post(`${base}/${id}/${what}`, {}, { preserveScroll: true });
  return (
    <AppShell title="Record sale">
      <Head title="Record sale" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="Record sale"
          description="An in-store sale, linked to the customer — it counts towards their rewards and the person who referred them."
        />
        {errors.sale && (
          <p role="alert" className="text-danger text-sm">
            {errors.sale}
          </p>
        )}
        <Card>
          <CardContent className="pt-6">
            <form
              aria-label="Record a sale"
              className="grid gap-4"
              onSubmit={(e) => {
                e.preventDefault();
                form.post(base, {
                  preserveScroll: true,
                  onSuccess: () => {
                    form.reset();
                    setFound(null);
                  },
                });
              }}
            >
              <div className="flex flex-wrap items-end gap-2">
                <Field label="Customer’s phone" error={form.errors.phone}>
                  <Input
                    type="tel"
                    inputMode="tel"
                    autoComplete="off"
                    value={form.data.phone}
                    onChange={(e) => {
                      form.setData('phone', e.target.value);
                      setFound(null);
                    }}
                  />
                </Field>
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => void find()}
                  disabled={!form.data.phone && !form.data.email}
                >
                  Find
                </Button>
              </div>
              <p aria-live="polite" className="text-sm">
                {found === 'new' && 'New customer — enter their name.'}
                {found !== null &&
                  found !== 'new' &&
                  `Found: ${found.name}${found.email ? ' (receipt by email)' : ''}`}
              </p>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Name" error={form.errors.name}>
                  <Input
                    value={form.data.name}
                    maxLength={80}
                    onChange={(e) => form.setData('name', e.target.value)}
                  />
                </Field>
                <Field label="Email (optional — for their receipt)" error={form.errors.email}>
                  <Input
                    type="email"
                    value={form.data.email}
                    maxLength={120}
                    onChange={(e) => form.setData('email', e.target.value)}
                  />
                </Field>
                <Field label="Amount paid (R)" error={form.errors.amount}>
                  <Input
                    type="number"
                    inputMode="decimal"
                    min="0.01"
                    step="0.01"
                    value={form.data.amount}
                    onChange={(e) => form.setData('amount', e.target.value)}
                  />
                </Field>
                {branches.length > 0 && (
                  <Field label="Branch" error={form.errors.branch_id}>
                    <select
                      className="border-border bg-bg h-10 w-full rounded-md border px-3 text-sm"
                      value={form.data.branch_id}
                      onChange={(e) => form.setData('branch_id', e.target.value)}
                    >
                      {branches.map((b) => (
                        <option key={b.id} value={b.id}>
                          {b.name}
                        </option>
                      ))}
                    </select>
                  </Field>
                )}
              </div>
              <fieldset className="grid gap-2">
                <legend className="text-sm font-medium">How they paid</legend>
                <div className="flex flex-wrap gap-2">
                  {Object.entries(methods).map(([key, label]) => (
                    <label
                      key={key}
                      className={`border-border flex min-h-11 items-center gap-2 rounded-md border px-4 text-sm ${form.data.method === key ? 'ring-2' : ''}`}
                    >
                      <input
                        type="radio"
                        name="method"
                        value={key}
                        checked={form.data.method === key}
                        onChange={() => form.setData('method', key)}
                      />
                      {label}
                    </label>
                  ))}
                </div>
              </fieldset>
              <Field label="Note (optional)" error={form.errors.note}>
                <Input
                  value={form.data.note}
                  maxLength={200}
                  onChange={(e) => form.setData('note', e.target.value)}
                />
              </Field>
              {threshold > 0 && !canApprove && (
                <p className="text-fg-muted text-sm">
                  Sales over {rand(threshold)} need a manager’s approval.
                </p>
              )}
              <Button type="submit" className="justify-self-start" loading={form.processing}>
                Record sale
              </Button>
            </form>
          </CardContent>
        </Card>

        <section aria-labelledby="recent-title" className="grid gap-3">
          <h2 id="recent-title" className="text-lg font-semibold">
            Today and waiting for approval
          </h2>
          {sales.length === 0 ? (
            <p className="text-fg-muted text-sm">No sales recorded today.</p>
          ) : (
            <ul aria-label="Recent sales" className="grid gap-2">
              {sales.map((s) => (
                <li key={s.id} className="border-border grid gap-2 rounded-lg border p-3 text-sm">
                  <div className="flex flex-wrap items-center gap-2">
                    <strong>{s.customer}</strong> {rand(s.amount_cents)} · {s.method} · {s.at} · by{' '}
                    {s.by}
                    {s.status === 'pending' && <Badge tone="warning">Needs approval</Badge>}
                    {s.status === 'declined' && <Badge tone="neutral">Declined</Badge>}
                    {s.refunded_cents > 0 && (
                      <Badge tone="neutral">Refunded {rand(s.refunded_cents)}</Badge>
                    )}
                  </div>
                  {s.note && <span className="text-fg-muted">{s.note}</span>}
                  {canApprove && s.status === 'pending' && (
                    <div className="flex gap-2">
                      <Button size="sm" onClick={() => act(s.id, 'approve')}>
                        Approve
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => act(s.id, 'decline')}>
                        Decline
                      </Button>
                    </div>
                  )}
                  {canApprove &&
                    s.status === 'recorded' &&
                    s.refunded_cents < s.amount_cents &&
                    (refund?.id === s.id ? (
                      <form
                        aria-label={`Refund ${s.customer}’s sale`}
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(e) => {
                          e.preventDefault();
                          router.post(
                            `${base}/${s.id}/refund`,
                            { amount: refund.amount },
                            { preserveScroll: true, onSuccess: () => setRefund(null) },
                          );
                        }}
                      >
                        <Field label="Refund (R)">
                          <Input
                            type="number"
                            inputMode="decimal"
                            min="0.01"
                            step="0.01"
                            value={refund.amount}
                            onChange={(e) => setRefund({ id: s.id, amount: e.target.value })}
                          />
                        </Field>
                        <Button size="sm" type="submit">
                          Refund
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setRefund(null)}>
                          Cancel
                        </Button>
                      </form>
                    ) : (
                      <Button
                        size="sm"
                        variant="ghost"
                        className="justify-self-start"
                        onClick={() =>
                          setRefund({
                            id: s.id,
                            amount: String((s.amount_cents - s.refunded_cents) / 100),
                          })
                        }
                      >
                        Refund…
                      </Button>
                    ))}
                </li>
              ))}
            </ul>
          )}
        </section>

        {canManage && <ApprovalLimit threshold={threshold} />}
      </div>
    </AppShell>
  );
}

function ApprovalLimit({ threshold }: { threshold: number }) {
  const settings = `${window.location.pathname.replace(/\/sales\/?$/, '')}/settings/sales`;
  const form = useForm({ amount: String(threshold / 100) });
  return (
    <Card>
      <CardHeader>
        <CardTitle>Approval</CardTitle>
      </CardHeader>
      <CardContent>
        <form
          aria-label="Approval limit"
          className="flex flex-wrap items-end gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            form.put(settings, { preserveScroll: true });
          }}
        >
          <Field
            label="Sales over this amount need a manager (R; 0 = never)"
            error={form.errors.amount}
          >
            <Input
              type="number"
              inputMode="decimal"
              min="0"
              step="0.01"
              value={form.data.amount}
              onChange={(e) => form.setData('amount', e.target.value)}
            />
          </Field>
          <Button type="submit" loading={form.processing}>
            Save
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
