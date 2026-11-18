import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
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

interface Owed {
  key: string;
  kind: 'invoice' | 'deposit' | 'order' | 'sale';
  title: string;
  customer: string;
  phone: string | null;
  amount_cents: number;
  since: string;
  due: string | null;
  link: string;
  settle?: string;
}
interface InvoiceRow {
  id: string;
  label: string;
  customer: string;
  total_cents: number;
  status: 'draft' | 'sent' | 'paid' | 'void';
  due_on: string;
  overdue: boolean;
  sent_by: string | null;
  reminders_sent: number;
  reminders_on: boolean;
  paid_how: string | null;
  link: string;
  whatsapp: string;
  has_phone: boolean;
}
interface Props {
  owed: Owed[];
  total_cents: number;
  invoices: InvoiceRow[];
  paidMethods: Record<string, string>;
  today: string;
}

const rand = (c: number) =>
  `R${(c / 100).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const tenantBase = () => '/' + window.location.pathname.split('/')[1];
const KIND: Record<Owed['kind'], string> = {
  invoice: 'Invoice',
  deposit: 'Deposit',
  order: 'Order',
  sale: 'On account',
};

/** Mark something paid in person or by EFT. */
function PaidButton({
  path,
  label,
  methods,
}: {
  path: string;
  label: string;
  methods: Props['paidMethods'];
}) {
  const [open, setOpen] = useState(false);
  if (!open) {
    return (
      <Button
        size="sm"
        variant="secondary"
        aria-label={`Mark paid: ${label}`}
        onClick={() => setOpen(true)}
      >
        Mark paid
      </Button>
    );
  }
  return (
    <span className="flex flex-wrap gap-1">
      {Object.entries(methods).map(([how, name]) => (
        <Button
          key={how}
          size="sm"
          variant="secondary"
          aria-label={`${name}: ${label}`}
          onClick={() =>
            router.post(`${tenantBase()}${path}`, { how, to: 'paid' }, { preserveScroll: true })
          }
        >
          {name}
        </Button>
      ))}
    </span>
  );
}

function NewInvoice({ today }: { today: string }) {
  const due = new Date(Date.parse(today) + 7 * 86400000).toISOString().slice(0, 10);
  const form = useForm({
    customer_name: '',
    customer_phone: '',
    customer_email: '',
    lines: [{ description: '', quantity: 1, unit: '' as string | number }],
    due_on: due,
    vat: false,
    vat_number: '',
    send: true,
  });
  const errors = form.errors as Record<string, string | undefined>;
  const total =
    form.data.lines.reduce((t, l) => t + Number(l.quantity || 0) * Number(l.unit || 0), 0) *
    (form.data.vat ? 1.15 : 1);
  const setLine = (i: number, k: 'description' | 'quantity' | 'unit', v: string) =>
    form.setData(
      'lines',
      form.data.lines.map((l, j) =>
        j === i ? { ...l, [k]: k === 'quantity' ? Number(v) : v } : l,
      ),
    );
  return (
    <form
      className="grid gap-3"
      onSubmit={(e) => {
        e.preventDefault();
        form.post(`${tenantBase()}/money/invoices`, {
          preserveScroll: true,
          onSuccess: () => form.reset(),
        });
      }}
    >
      <Field label="Customer’s name" error={form.errors.customer_name}>
        <Input
          value={form.data.customer_name}
          onChange={(e) => form.setData('customer_name', e.target.value)}
        />
      </Field>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Mobile (for WhatsApp or SMS)" error={form.errors.customer_phone}>
          <Input
            type="tel"
            value={form.data.customer_phone}
            onChange={(e) => form.setData('customer_phone', e.target.value)}
          />
        </Field>
        <Field label="Email" error={form.errors.customer_email}>
          <Input
            type="email"
            value={form.data.customer_email}
            onChange={(e) => form.setData('customer_email', e.target.value)}
          />
        </Field>
      </div>
      <fieldset className="grid gap-2">
        <legend className="mb-1 text-sm font-medium">What it’s for</legend>
        {form.data.lines.map((l, i) => (
          <div key={i} className="grid grid-cols-[1fr_4.5rem_6.5rem] gap-2">
            <Input
              aria-label={`Line ${i + 1}: description`}
              placeholder="e.g. Wedding hair, 3 people"
              value={l.description}
              onChange={(e) => setLine(i, 'description', e.target.value)}
            />
            <Input
              aria-label={`Line ${i + 1}: quantity`}
              inputMode="numeric"
              value={String(l.quantity)}
              onChange={(e) => setLine(i, 'quantity', e.target.value)}
            />
            <Input
              aria-label={`Line ${i + 1}: price each (R)`}
              inputMode="decimal"
              placeholder="R"
              value={String(l.unit)}
              onChange={(e) => setLine(i, 'unit', e.target.value)}
            />
          </div>
        ))}
        {(errors.lines ?? errors['lines.0.unit'] ?? errors['lines.0.description']) && (
          <p className="text-danger text-sm">
            {errors.lines ?? errors['lines.0.unit'] ?? errors['lines.0.description']}
          </p>
        )}
        <Button
          type="button"
          size="sm"
          variant="ghost"
          onClick={() =>
            form.setData('lines', [...form.data.lines, { description: '', quantity: 1, unit: '' }])
          }
        >
          Add a line
        </Button>
      </fieldset>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Due on" error={form.errors.due_on}>
          <Input
            type="date"
            value={form.data.due_on}
            onChange={(e) => form.setData('due_on', e.target.value)}
          />
        </Field>
        <div className="grid content-end gap-2 text-sm">
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={form.data.vat}
              onChange={(e) => form.setData('vat', e.target.checked)}
            />
            Add VAT (15%) — I’m VAT-registered
          </label>
        </div>
      </div>
      {form.data.vat && (
        <Field label="Your VAT number" error={form.errors.vat_number}>
          <Input
            value={form.data.vat_number}
            onChange={(e) => form.setData('vat_number', e.target.value)}
          />
        </Field>
      )}
      <p className="text-sm font-medium">Total: {rand(Math.round(total * 100))}</p>
      <div className="flex flex-wrap gap-2">
        <Button type="submit" loading={form.processing} onClick={() => form.setData('send', true)}>
          Save and send
        </Button>
        <Button type="submit" variant="secondary" onClick={() => form.setData('send', false)}>
          Save as a draft
        </Button>
      </div>
    </form>
  );
}

export default function Owed({ owed, total_cents, invoices, paidMethods, today }: Props) {
  const warning = (usePage().props.flash as { warning?: string | null } | undefined)?.warning;
  const post = (path: string, data: Record<string, string> = {}) =>
    router.post(`${tenantBase()}${path}`, data, { preserveScroll: true });
  return (
    <AppShell title="Who owes me">
      <Head title="Who owes me" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Who owes me"
          description="Money customers still owe you: deposits, pay-later orders, sales on account and invoices — in one list."
        />
        {warning && (
          <p role="alert" className="text-danger text-sm">
            {warning}
          </p>
        )}
        <Card>
          <CardHeader>
            <CardTitle>
              {owed.length === 0
                ? 'Nobody owes you anything'
                : `${rand(total_cents)} owed by ${owed.length} ${owed.length === 1 ? 'customer' : 'customers'}`}
            </CardTitle>
          </CardHeader>
          {owed.length > 0 && (
            <CardContent>
              <ul className="grid gap-3 text-sm">
                {owed.map((o) => (
                  <li
                    key={o.key}
                    className="border-border flex flex-wrap items-center justify-between gap-2 border-b pb-3 last:border-0"
                  >
                    <div>
                      <p className="font-medium">
                        {o.customer} · {rand(o.amount_cents)}
                      </p>
                      <p className="text-fg-muted">
                        {KIND[o.kind]}: {o.title}
                        {o.due ? ` · due ${o.due}` : ` · since ${o.since}`}
                        {o.due && o.due < today ? ' · overdue' : ''}
                      </p>
                    </div>
                    <span className="flex flex-wrap gap-2">
                      <Button size="sm" variant="ghost" asChild>
                        <a
                          href={`${tenantBase()}${o.link}`}
                          aria-label={`Open: ${o.title}, ${o.customer}`}
                        >
                          Open
                        </a>
                      </Button>
                      {o.settle && (
                        <PaidButton
                          path={o.settle}
                          label={`${o.title}, ${o.customer}`}
                          methods={paidMethods}
                        />
                      )}
                    </span>
                  </li>
                ))}
              </ul>
            </CardContent>
          )}
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>New invoice</CardTitle>
          </CardHeader>
          <CardContent>
            <NewInvoice today={today} />
          </CardContent>
        </Card>
        {invoices.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Invoices</CardTitle>
            </CardHeader>
            <CardContent>
              <ul className="grid gap-3 text-sm">
                {invoices.map((i) => (
                  <li
                    id={`invoice-${i.id}`}
                    key={i.id}
                    className="border-border grid gap-2 border-b pb-3 last:border-0"
                  >
                    <p className="flex flex-wrap justify-between gap-2">
                      <span className="font-medium">
                        {i.label} · {i.customer} · {rand(i.total_cents)}
                      </span>
                      <span className={i.overdue ? 'text-danger' : 'text-fg-muted'}>
                        {i.status === 'paid'
                          ? `Paid${i.paid_how ? ` (${i.paid_how.replace('_', ' ')})` : ''}`
                          : i.status === 'void'
                            ? 'Cancelled'
                            : i.status === 'draft'
                              ? 'Draft'
                              : `${i.overdue ? 'Overdue' : 'Due'} ${i.due_on}${i.reminders_sent > 0 ? ` · ${i.reminders_sent} reminder${i.reminders_sent === 1 ? '' : 's'}` : ''}`}
                      </span>
                    </p>
                    {(i.status === 'draft' || i.status === 'sent') && (
                      <div className="flex flex-wrap gap-2">
                        {i.status === 'draft' ? (
                          <Button
                            size="sm"
                            aria-label={`Send ${i.label}`}
                            onClick={() => post(`/money/invoices/${i.id}/send`)}
                          >
                            Send
                          </Button>
                        ) : (
                          <Button
                            size="sm"
                            variant="secondary"
                            aria-label={`Send a reminder for ${i.label}`}
                            onClick={() => post(`/money/invoices/${i.id}/remind`)}
                          >
                            Send a reminder
                          </Button>
                        )}
                        {i.has_phone && (
                          <Button size="sm" variant="secondary" asChild>
                            <a
                              href={i.whatsapp}
                              target="_blank"
                              rel="noopener noreferrer"
                              aria-label={`Share on WhatsApp: ${i.label}`}
                            >
                              Share on WhatsApp
                            </a>
                          </Button>
                        )}
                        <Button
                          size="sm"
                          variant="ghost"
                          aria-label={`Copy link: ${i.label}`}
                          onClick={() => void navigator.clipboard?.writeText(i.link)}
                        >
                          Copy link
                        </Button>
                        <PaidButton
                          path={`/money/invoices/${i.id}/paid`}
                          label={i.label}
                          methods={paidMethods}
                        />
                        {i.status === 'sent' && (
                          <label className="flex items-center gap-2">
                            <input
                              type="checkbox"
                              checked={i.reminders_on}
                              onChange={(e) =>
                                router.put(
                                  `${tenantBase()}/money/invoices/${i.id}/reminders`,
                                  { on: e.target.checked },
                                  { preserveScroll: true },
                                )
                              }
                            />
                            Automatic reminders
                          </label>
                        )}
                        <Button
                          size="sm"
                          variant="ghost"
                          aria-label={`Cancel invoice ${i.label}`}
                          onClick={() => post(`/money/invoices/${i.id}/void`)}
                        >
                          Cancel invoice
                        </Button>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
