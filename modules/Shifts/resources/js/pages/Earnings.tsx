import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { PageHeader } from '@/design-system/components/feedback';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import AppShell from '@/layouts/AppShell';
import { __, money } from '@/i18n';

interface Row {
  member_id: string;
  name: string;
  hours: number;
  services_cents: number;
  products_cents: number;
  commission_cents: number;
  tips_cents: number;
  earned_cents: number;
  services_bp: number;
  products_bp: number;
}
interface Props {
  month: { value: string; label: string; previous: string; next: string };
  rows: Row[];
  totals: {
    services_cents: number;
    products_cents: number;
    commission_cents: number;
    tips_cents: number;
  };
  members: { id: string; name: string }[];
}

const base = () => '/' + window.location.pathname.split('/')[1] + '/shifts';

function Rate({ row }: { row: Row }) {
  const [services, setServices] = useState(String(row.services_bp / 100));
  const [products, setProducts] = useState(String(row.products_bp / 100));
  return (
    <form
      className="flex flex-wrap items-end gap-2"
      onSubmit={(e) => {
        e.preventDefault();
        router.put(
          `${base()}/rates/${row.member_id}`,
          { services, products },
          { preserveScroll: true },
        );
      }}
    >
      <Field label={`${__('Services %')}: ${row.name}`}>
        <Input
          inputMode="decimal"
          className="w-24"
          value={services}
          onChange={(e) => setServices(e.target.value)}
        />
      </Field>
      <Field label={`${__('Products %')}: ${row.name}`}>
        <Input
          inputMode="decimal"
          className="w-24"
          value={products}
          onChange={(e) => setProducts(e.target.value)}
        />
      </Field>
      <Button type="submit" size="sm" variant="secondary">
        {__('Save')}
      </Button>
    </form>
  );
}

/**
 * Settings → Team earnings (Sprint 26.4): what each person earned this month — hours clocked, services done, products
 * sold, commission at their rates, and tips — to export for the bookkeeper. Not payroll: no PAYE or UIF.
 */
export default function Earnings({ month, rows, totals, members }: Props) {
  const tip = useForm({ member_id: members[0]?.id ?? '', amount: '' });
  return (
    <AppShell title={__('Team earnings')}>
      <Head title={__('Team earnings')} />
      <div className="grid gap-6">
        <PageHeader
          title={__('Team earnings')}
          description={__(
            'Commission and tips for the month. Not payroll: no PAYE, UIF or other deductions.',
          )}
        />
        <Card>
          <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
            <CardTitle>{month.label}</CardTitle>
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" size="sm" asChild>
                <Link href={`${base()}/earnings?month=${month.previous}`}>
                  {__('Previous month')}
                </Link>
              </Button>
              <Button variant="secondary" size="sm" asChild>
                <Link href={`${base()}/earnings?month=${month.next}`}>{__('Next month')}</Link>
              </Button>
              <Button size="sm" asChild>
                <a href={`${base()}/earnings/export?month=${month.value}`}>
                  {__('Download spreadsheet')}
                </a>
              </Button>
            </div>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead>
                <tr className="text-fg-muted text-left">
                  <th className="py-2 font-medium">{__('Team member')}</th>
                  <th className="py-2 text-right font-medium">{__('Hours')}</th>
                  <th className="py-2 text-right font-medium">{__('Services')}</th>
                  <th className="py-2 text-right font-medium">{__('Products')}</th>
                  <th className="py-2 text-right font-medium">{__('Commission')}</th>
                  <th className="py-2 text-right font-medium">{__('Tips')}</th>
                  <th className="py-2 text-right font-medium">{__('Earned')}</th>
                </tr>
              </thead>
              <tbody className="divide-border divide-y">
                {rows.map((r) => (
                  <tr key={r.member_id}>
                    <th scope="row" className="py-2 text-left font-medium">
                      {r.name}
                    </th>
                    <td className="py-2 text-right tabular-nums">{r.hours}</td>
                    <td className="py-2 text-right tabular-nums">{money(r.services_cents)}</td>
                    <td className="py-2 text-right tabular-nums">{money(r.products_cents)}</td>
                    <td className="py-2 text-right tabular-nums">{money(r.commission_cents)}</td>
                    <td className="py-2 text-right tabular-nums">{money(r.tips_cents)}</td>
                    <td className="py-2 text-right font-semibold tabular-nums">
                      {money(r.earned_cents)}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="border-border border-t font-semibold">
                  <th scope="row" className="py-2 text-left">
                    {__('Total')}
                  </th>
                  <td />
                  <td className="py-2 text-right tabular-nums">{money(totals.services_cents)}</td>
                  <td className="py-2 text-right tabular-nums">{money(totals.products_cents)}</td>
                  <td className="py-2 text-right tabular-nums">{money(totals.commission_cents)}</td>
                  <td className="py-2 text-right tabular-nums">{money(totals.tips_cents)}</td>
                  <td className="py-2 text-right tabular-nums">
                    {money(totals.commission_cents + totals.tips_cents)}
                  </td>
                </tr>
              </tfoot>
            </table>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Commission rates')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3">
            {rows.map((r) => (
              <Rate key={r.member_id} row={r} />
            ))}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Record a tip')}</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              className="flex flex-wrap items-end gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                tip.post(`${base()}/tips`, {
                  preserveScroll: true,
                  onSuccess: () => tip.reset('amount'),
                });
              }}
            >
              <Field label={__('For')} error={tip.errors.member_id}>
                <select
                  className="border-border bg-surface h-10 rounded-md border px-2"
                  value={tip.data.member_id}
                  onChange={(e) => tip.setData('member_id', e.target.value)}
                >
                  {members.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label={__('Amount')} error={tip.errors.amount}>
                <Input
                  inputMode="decimal"
                  className="w-32"
                  value={tip.data.amount}
                  onChange={(e) => tip.setData('amount', e.target.value)}
                />
              </Field>
              <Button type="submit" variant="secondary" disabled={tip.processing}>
                {__('Record')}
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
