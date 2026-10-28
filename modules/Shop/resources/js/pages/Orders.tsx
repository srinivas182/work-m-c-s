import { Head, Link } from '@inertiajs/react';
import { Badge, EmptyState, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { ShoppingBag } from 'lucide-react';

export const STATUS: Record<string, string> = {
  awaiting_payment: 'Awaiting payment',
  paid: 'Paid',
  packed: 'Packed',
  ready: 'Ready for collection',
  out_for_delivery: 'On its way',
  done: 'Done',
  cancelled: 'Cancelled',
  expired: 'Expired',
};
export const rand = (c: number) => `R${(c / 100).toFixed(2)}`;

interface Row {
  id: string;
  number: number;
  customer: string;
  total_cents: number;
  status: string;
  payment: string;
  fulfilment: string;
  placed: string;
}

const TABS: [string, string][] = [
  ['todo', 'To do'],
  ['awaiting', 'Awaiting payment'],
  ['sent', 'Ready / on its way'],
  ['done', 'Done'],
  ['cancelled', 'Cancelled'],
  ['all', 'All'],
];

export default function Orders({
  show,
  counts,
  orders,
}: {
  show: string;
  counts: Record<string, number>;
  orders: Row[];
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  return (
    <AppShell title="Orders">
      <Head title="Orders" />
      <div className="grid max-w-5xl gap-6">
        <PageHeader
          title="Orders"
          description="Orders from your website. Card payments are confirmed automatically; mark EFT orders paid when the money arrives."
        />
        <nav aria-label="Show orders" className="flex flex-wrap gap-2">
          {TABS.map(([key, label]) => (
            <Link
              key={key}
              href={`${base}?show=${key}`}
              aria-current={show === key ? 'page' : undefined}
              className={`rounded-full border px-3 py-1 text-sm ${show === key ? 'border-primary bg-primary text-primary-fg' : 'border-border'}`}
            >
              {label}
              {counts[key] !== undefined ? ` (${counts[key]})` : ''}
            </Link>
          ))}
        </nav>
        {orders.length === 0 ? (
          <EmptyState
            icon={ShoppingBag}
            title="No orders here"
            description="Orders appear as soon as customers place them on your website."
          />
        ) : (
          <ul className="grid gap-2" aria-label="Orders">
            {orders.map((o) => (
              <li key={o.id}>
                <Link
                  href={`${base}/${o.id}`}
                  className="border-border hover:bg-surface flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                >
                  <span>
                    <strong>#{o.number}</strong> · {o.customer} · {o.placed}
                  </span>
                  <span className="flex items-center gap-2 text-sm">
                    {rand(o.total_cents)} · {o.payment === 'card' ? 'Card' : 'EFT'} ·{' '}
                    {o.fulfilment === 'delivery' ? 'Delivery' : 'Collection'}
                    <Badge
                      tone={
                        o.status === 'awaiting_payment'
                          ? 'warning'
                          : ['cancelled', 'expired'].includes(o.status)
                            ? 'neutral'
                            : 'success'
                      }
                    >
                      {STATUS[o.status] ?? o.status}
                    </Badge>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </AppShell>
  );
}
