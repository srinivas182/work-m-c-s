import { Head, router, useForm } from '@inertiajs/react';
import { Tag } from 'lucide-react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Checkbox,
  EmptyState,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import { HelpTip } from '@/components/HelpTip';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';
import { __ } from '@/i18n';

interface Coupon {
  id: string;
  code: string;
  kind: 'amount' | 'percent';
  value: number;
  applies_to: 'shop' | 'bookings' | 'both';
  min_spend_cents: number;
  ends_at: string | null;
  max_uses: number | null;
  once_per_customer: boolean;
  active: boolean;
  uses: number;
  given_cents: number;
}

const off = (c: Coupon) =>
  c.kind === 'percent' ? `${c.value / 100}% off` : `${rand(c.value)} off`;
const where = {
  shop: 'the shop',
  bookings: 'booking deposits',
  both: 'the shop and booking deposits',
};

/** Customers → Coupons: codes for the shop and booking deposits. */
export default function Coupons({
  coupons,
  campaigns,
}: {
  coupons: Coupon[];
  campaigns: { id: string; name: string }[];
}) {
  const form = useForm({
    code: '',
    kind: 'percent',
    value: '',
    applies_to: 'both',
    min_spend: '',
    ends_on: '',
    max_uses: '',
    once_per_customer: false,
    campaign_id: '',
  });
  const base = window.location.pathname.replace(/\/$/, '');
  return (
    <AppShell title={__('Coupons')}>
      <Head title={__('Coupons')} />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title={__('Coupons')}
          description={__('Codes customers enter at checkout or when paying a booking deposit.')}
        />
        <Card>
          <CardHeader>
            <CardTitle>{__('New coupon')}</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              aria-label={__('New coupon')}
              className="grid gap-3 text-sm sm:grid-cols-2"
              onSubmit={(e) => {
                e.preventDefault();
                form.post(base, { preserveScroll: true, onSuccess: () => form.reset() });
              }}
            >
              <Field label={__('Code')} error={form.errors.code}>
                <Input
                  value={form.data.code}
                  onChange={(e) => form.setData('code', e.target.value.toUpperCase())}
                />
              </Field>
              <Field label={__('Discount')} error={form.errors.value}>
                <div className="flex gap-2">
                  <select
                    aria-label={__('Kind of discount')}
                    className="border-border bg-bg h-10 rounded-md border px-3"
                    value={form.data.kind}
                    onChange={(e) => form.setData('kind', e.target.value)}
                  >
                    <option value="percent">%</option>
                    <option value="amount">{__('Rand')}</option>
                  </select>
                  <Input
                    aria-label={
                      form.data.kind === 'percent' ? 'Discount in percent' : 'Discount in rand'
                    }
                    inputMode="decimal"
                    value={form.data.value}
                    onChange={(e) => form.setData('value', e.target.value)}
                  />
                </div>
              </Field>
              <Field label={__('Use on')} error={form.errors.applies_to}>
                <select
                  className="border-border bg-bg h-10 w-full rounded-md border px-3"
                  value={form.data.applies_to}
                  onChange={(e) => form.setData('applies_to', e.target.value)}
                >
                  <option value="both">{__('The shop and booking deposits')}</option>
                  <option value="shop">{__('The shop only')}</option>
                  <option value="bookings">{__('Booking deposits only')}</option>
                </select>
              </Field>
              <Field label={__('Minimum spend in rand (optional)')} error={form.errors.min_spend}>
                <Input
                  inputMode="decimal"
                  value={form.data.min_spend}
                  onChange={(e) => form.setData('min_spend', e.target.value)}
                />
              </Field>
              <Field label={__('Last day (optional)')} error={form.errors.ends_on}>
                <Input
                  type="date"
                  value={form.data.ends_on}
                  onChange={(e) => form.setData('ends_on', e.target.value)}
                />
              </Field>
              <Field label={__('How many times in total (optional)')} error={form.errors.max_uses}>
                <Input
                  inputMode="numeric"
                  value={form.data.max_uses}
                  onChange={(e) => form.setData('max_uses', e.target.value)}
                />
              </Field>
              {campaigns.length > 0 && (
                <Field label={__('For a campaign (optional)')} error={form.errors.campaign_id}>
                  <select
                    className="border-border bg-bg h-10 w-full rounded-md border px-3"
                    value={form.data.campaign_id}
                    onChange={(e) => form.setData('campaign_id', e.target.value)}
                  >
                    <option value="">{__('None')}</option>
                    {campaigns.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                </Field>
              )}
              <label className="flex items-center gap-2 self-end">
                <Checkbox
                  checked={form.data.once_per_customer}
                  onCheckedChange={(on) => form.setData('once_per_customer', on === true)}
                />
                {__('Once per customer')}{' '}
                <HelpTip>
                  {__('We recognise customers by their phone number, however it’s written.')}
                </HelpTip>
              </label>
              <Button type="submit" className="justify-self-start" loading={form.processing}>
                {__('Create coupon')}
              </Button>
            </form>
          </CardContent>
        </Card>
        {coupons.length === 0 ? (
          <EmptyState
            icon={Tag}
            title={__('No coupons yet')}
            description={__('Create one above, then share the code in a campaign.')}
          />
        ) : (
          <ul aria-label={__('Coupons')} className="grid gap-2">
            {coupons.map((c) => (
              <li
                key={c.id}
                className="border-border flex flex-wrap items-center gap-3 rounded-lg border p-3 text-sm"
              >
                <strong className="font-mono">{c.code}</strong>
                <span>
                  {off(c)} {where[c.applies_to]}
                  {c.min_spend_cents > 0 ? ` over ${rand(c.min_spend_cents)}` : ''}
                </span>
                <Badge tone={c.active ? 'success' : 'neutral'}>{c.active ? 'On' : 'Off'}</Badge>
                <span className="text-fg-muted">
                  {__('Used')} {c.uses}
                  {c.max_uses ? ` of ${c.max_uses}` : ''} · {rand(c.given_cents)} {__('given')}
                  {c.ends_at ? ` · until ${c.ends_at.slice(0, 10)}` : ''}
                </span>
                {c.active && (
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => router.post(`${base}/${c.id}/off`, {}, { preserveScroll: true })}
                  >
                    {__('Switch off')}
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    </AppShell>
  );
}
