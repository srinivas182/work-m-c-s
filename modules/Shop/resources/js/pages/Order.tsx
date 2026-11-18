import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { Checkbox } from '@/design-system/components/choice';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { STATUS, rand } from './Orders';
import { __, currencySymbol } from '@/i18n';

interface OrderData {
  id: string;
  number: number;
  status: string;
  payment: 'card' | 'offline';
  fulfilment: 'collection' | 'delivery';
  branch: string | null;
  shipping: {
    provider: 'bobgo' | 'pargo';
    service: string | null;
    pickup_point: string | null;
    tracking_reference: string | null;
    tracking_url: string | null;
    label_url: string | null;
    can_ship: boolean;
    /** Courier orders: the box to pack it in, or none fits. */
    parcel?: {
      weight_kg: number;
      size_cm: { length: number; width: number; height: number } | null;
      box: string | null;
      fits: boolean;
    } | null;
  } | null;

  customer: { name: string; phone: string; email: string | null };
  address: string | null;
  notes: string | null;
  subtotal_cents: number;
  delivery_cents: number;
  total_cents: number;
  refunded_cents: number;
  items: { name: string; label: string | null; quantity: number; line_cents: number }[];
  timeline: {
    event: string;
    by: string | null;
    note: string | null;
    amount_cents: number | null;
    at: string;
  }[];
  next: string[];
  can_cancel: boolean;
  can_refund: boolean;
}

const STEP: Record<string, string> = {
  paid: 'Mark as paid',
  packed: 'Mark as packed',
  ready: 'Ready for collection',
  out_for_delivery: 'On its way',
  done: 'Mark as done',
};
const EVENT: Record<string, string> = { placed: 'Placed', refunded: 'Refunded', ...STATUS };

export default function Order({ order: o }: { order: OrderData }) {
  const base = window.location.pathname.replace(/\/$/, '');
  const cancel = useForm({ reason: '' });
  const refund = useForm({ amount: '', restock: false, note: '' });
  const [how, setHow] = useState('eft'); // how a pay-later order was paid (Sprint 22.3)
  const err =
    (cancel.errors as Record<string, string | undefined>).order ??
    (refund.errors as Record<string, string | undefined>).order;
  const left = o.total_cents - o.refunded_cents;
  return (
    <AppShell title={`Order #${o.number}`}>
      <Head title={`Order #${o.number}`} />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title={`Order #${o.number}`}
          description={`${o.payment === 'card' ? 'Card' : 'EFT or on collection'} · ${o.fulfilment === 'delivery' ? 'Delivery' : 'Collection'}${o.branch ? ` · ${o.branch}` : ''}`}
          actions={
            <Badge tone={o.status === 'awaiting_payment' ? 'warning' : 'neutral'}>
              {STATUS[o.status] ?? o.status}
            </Badge>
          }
        />
        <p>
          <Link href={base.replace(/\/[^/]+$/, '')} className="underline">
            {__('← All orders')}
          </Link>
        </p>
        {o.shipping && (
          <section
            aria-label={__('Shipping')}
            className="border-border grid gap-2 rounded-lg border p-4 text-sm"
          >
            <p>
              <strong>
                {o.shipping.provider === 'pargo' ? 'Pargo pickup point' : 'Courier (Bob Go)'}
              </strong>
              {o.shipping.pickup_point
                ? ` · ${o.shipping.pickup_point}`
                : o.shipping.service
                  ? ` · ${o.shipping.service}`
                  : ''}
            </p>
            {o.shipping.parcel &&
              (o.shipping.parcel.fits ? (
                o.shipping.parcel.box &&
                o.shipping.parcel.size_cm && (
                  <p>
                    {__('Pack in:')} {o.shipping.parcel.box} ({o.shipping.parcel.size_cm.length} ×{' '}
                    {o.shipping.parcel.size_cm.width} × {o.shipping.parcel.size_cm.height}{' '}
                    {__('cm) ·')} {o.shipping.parcel.weight_kg} {__('kg')}
                  </p>
                )
              ) : (
                <p role="note" className="text-warning-11 font-medium">
                  {__(
                    'None of your boxes fits this order — it may need more than one parcel. Charged as',
                  )}{' '}
                  {o.shipping.parcel.weight_kg} {__('kg by size.')}
                </p>
              ))}
            {o.shipping.tracking_reference ? (
              <p className="flex flex-wrap gap-3">
                <span>
                  {__('Tracking:')} {o.shipping.tracking_reference}
                </span>
                {o.shipping.tracking_url && (
                  <a
                    className="underline"
                    href={o.shipping.tracking_url}
                    target="_blank"
                    rel="noreferrer"
                  >
                    {__('Track it')}
                  </a>
                )}
                {o.shipping.label_url && (
                  <a
                    className="underline"
                    href={o.shipping.label_url}
                    target="_blank"
                    rel="noreferrer"
                  >
                    {__('Print the label')}
                  </a>
                )}
              </p>
            ) : (
              o.shipping.can_ship && (
                <Button
                  className="justify-self-start"
                  onClick={() => router.post(`${base}/ship`, {}, { preserveScroll: true })}
                >
                  {__('Create shipment')}
                </Button>
              )
            )}
          </section>
        )}
        {err && (
          <p role="alert" className="border-danger text-danger rounded-lg border p-3 text-sm">
            {err}
          </p>
        )}
        {o.next.length > 0 && (
          <div className="flex flex-wrap items-center gap-2">
            {o.next.includes('paid') && (
              <label className="flex items-center gap-2 text-sm">
                {__('Paid by')}
                <select
                  aria-label={__('How the order was paid')}
                  className="border-border bg-bg h-9 rounded-md border px-2"
                  value={how}
                  onChange={(e) => setHow(e.target.value)}
                >
                  <option value="eft">{__('EFT / bank transfer')}</option>
                  <option value="cash">{__('Cash')}</option>
                  <option value="card_machine">{__('Card machine')}</option>
                  <option value="qr">{__('SnapScan / Zapper at the counter')}</option>
                </select>
              </label>
            )}
            {o.next.map((to) => (
              <Button
                key={to}
                onClick={() =>
                  router.post(`${base}/advance`, to === 'paid' ? { to, how } : { to }, {
                    preserveScroll: true,
                  })
                }
              >
                {STEP[to]}
              </Button>
            ))}
          </div>
        )}
        <Card>
          <CardHeader>
            <CardTitle>{__('Items')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-2">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">{__('Items, quantities and prices')}</caption>
              <thead>
                <tr className="border-border border-b">
                  <th scope="col" className="py-2">
                    {__('Item')}
                  </th>
                  <th scope="col" className="py-2 text-right">
                    {__('Price')}
                  </th>
                </tr>
              </thead>
              <tbody>
                {o.items.map((i, k) => (
                  <tr key={k} className="border-border border-b">
                    <td className="py-2">
                      {i.quantity} × {i.name}
                      {i.label ? ` (${i.label})` : ''}
                    </td>
                    <td className="py-2 text-right">{rand(i.line_cents)}</td>
                  </tr>
                ))}
                {o.delivery_cents > 0 && (
                  <tr>
                    <td className="py-2">{__('Delivery')}</td>
                    <td className="py-2 text-right">{rand(o.delivery_cents)}</td>
                  </tr>
                )}
                <tr>
                  <th scope="row" className="py-2">
                    {__('Total (incl. VAT)')}
                  </th>
                  <td className="py-2 text-right font-semibold">{rand(o.total_cents)}</td>
                </tr>
                {o.refunded_cents > 0 && (
                  <tr>
                    <td className="py-2">{__('Refunded')}</td>
                    <td className="py-2 text-right">−{rand(o.refunded_cents)}</td>
                  </tr>
                )}
              </tbody>
            </table>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Customer')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-1 text-sm">
            <p className="font-medium">{o.customer.name}</p>
            <p>
              <a className="underline" href={`tel:${o.customer.phone.replace(/\s/g, '')}`}>
                {o.customer.phone}
              </a>
              {o.customer.email && (
                <>
                  {' · '}
                  <a className="underline" href={`mailto:${o.customer.email}`}>
                    {o.customer.email}
                  </a>
                </>
              )}
            </p>
            <p>{o.fulfilment === 'delivery' ? `Deliver to: ${o.address ?? ''}` : 'Collecting'}</p>
            {o.notes && (
              <p>
                {__('Notes:')} {o.notes}
              </p>
            )}
          </CardContent>
        </Card>
        {(o.can_cancel || o.can_refund) && (
          <Card>
            <CardHeader>
              <CardTitle>{__('Cancel or refund')}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-6">
              {o.can_cancel && (
                <form
                  aria-label={__('Cancel this order')}
                  className="flex flex-wrap items-end gap-2"
                  onSubmit={(e) => {
                    e.preventDefault();
                    cancel.post(`${base}/cancel`, { preserveScroll: true });
                  }}
                >
                  <Field label={__('Reason (optional)')}>
                    <Input
                      value={cancel.data.reason}
                      maxLength={300}
                      onChange={(e) => cancel.setData('reason', e.target.value)}
                    />
                  </Field>
                  <Button type="submit" variant="secondary" loading={cancel.processing}>
                    {__('Cancel order')}
                  </Button>
                </form>
              )}
              {o.can_refund && (
                <form
                  aria-label={__('Refund')}
                  className="grid gap-3 sm:grid-cols-2 sm:items-end"
                  onSubmit={(e) => {
                    e.preventDefault();
                    refund.transform((d) => ({
                      amount_cents: d.amount === '' ? null : Math.round(Number(d.amount) * 100),
                      restock: d.restock,
                      note: d.note || null,
                    }));
                    refund.post(`${base}/refund`, {
                      preserveScroll: true,
                      onSuccess: () => refund.reset(),
                    });
                  }}
                >
                  <Field
                    label={__('Amount (:currency)', { currency: currencySymbol() })}
                    hint={`Leave empty to refund all that’s left (${rand(left)}).${o.payment === 'offline' ? ' For EFT orders, pay the customer back yourself — this records it.' : ''}`}
                  >
                    <Input
                      type="number"
                      min={0.01}
                      step={0.01}
                      value={refund.data.amount}
                      onChange={(e) => refund.setData('amount', e.target.value)}
                    />
                  </Field>
                  <Field label={__('Note (optional)')}>
                    <Input
                      value={refund.data.note}
                      maxLength={300}
                      onChange={(e) => refund.setData('note', e.target.value)}
                    />
                  </Field>
                  <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                      checked={refund.data.restock}
                      onCheckedChange={(on) => refund.setData('restock', on === true)}
                    />
                    {__('Put the items back in stock')}
                  </label>
                  <Button
                    type="submit"
                    variant="secondary"
                    className="justify-self-start"
                    loading={refund.processing}
                  >
                    {__('Refund')}
                  </Button>
                </form>
              )}
            </CardContent>
          </Card>
        )}
        <Card>
          <CardHeader>
            <CardTitle>{__('Timeline')}</CardTitle>
          </CardHeader>
          <CardContent>
            <ol className="grid gap-2 text-sm" aria-label={__('What happened')}>
              {o.timeline.map((t, k) => (
                <li key={k}>
                  <strong>{EVENT[t.event] ?? t.event}</strong>
                  {t.amount_cents ? ` ${rand(t.amount_cents)}` : ''} · {t.at}
                  {t.by ? ` · ${t.by}` : ''}
                  {t.note ? ` — ${t.note}` : ''}
                </li>
              ))}
            </ol>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
