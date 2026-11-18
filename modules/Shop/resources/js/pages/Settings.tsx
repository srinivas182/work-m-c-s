import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/design-system/components/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/design-system/components/card';
import { Checkbox } from '@/design-system/components/choice';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Settings {
  collection_enabled: boolean;
  collection_address: string;
  delivery_enabled: boolean;
  delivery_fee_cents: number;
  delivery_note: string;
  offline_hold_hours: number;
  ships_from_branch_id: string | null;
}

const area = 'min-h-24 w-full rounded-md border border-border bg-bg p-3 text-sm';

export default function ShopSettings({
  settings,
  branches,
}: {
  settings: Settings;
  branches: { id: string; name: string }[];
}) {
  const form = useForm<Settings>({ ...settings });
  const errors = form.errors as Record<string, string | undefined>;
  return (
    <AppShell title={__('Shop settings')}>
      <Head title={__('Shop settings')} />
      <form
        aria-label={__('Shop settings')}
        className="grid max-w-3xl gap-6"
        onSubmit={(e) => {
          e.preventDefault();
          form.put(window.location.pathname, { preserveScroll: true });
        }}
      >
        <PageHeader
          title={__('Shop settings')}
          description={__(
            'How customers get their orders and how they pay. Prices include VAT; WURK takes no fee on your sales.',
          )}
        />
        <Card>
          <CardHeader>
            <CardTitle>{__('Collection and delivery')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4">
            <label className="flex items-center gap-2 text-sm font-medium">
              <Checkbox
                checked={form.data.collection_enabled}
                onCheckedChange={(on) => form.setData('collection_enabled', on === true)}
              />
              {__('Customers can collect')}
            </label>
            {errors.collection_enabled && (
              <p className="text-danger text-sm">{errors.collection_enabled}</p>
            )}
            {form.data.collection_enabled && (
              <Field label={__('Collection address')} error={errors.collection_address}>
                <textarea
                  className={area}
                  maxLength={300}
                  value={form.data.collection_address}
                  onChange={(e) => form.setData('collection_address', e.target.value)}
                />
              </Field>
            )}
            <label className="flex items-center gap-2 text-sm font-medium">
              <Checkbox
                checked={form.data.delivery_enabled}
                onCheckedChange={(on) => form.setData('delivery_enabled', on === true)}
              />
              {__('We deliver')}
            </label>
            {form.data.delivery_enabled && branches.length > 1 && (
              <Field label={__('Deliveries ship from')}>
                <select
                  className="border-border bg-bg h-10 w-full rounded-md border px-3 text-sm"
                  value={form.data.ships_from_branch_id ?? ''}
                  onChange={(e) => form.setData('ships_from_branch_id', e.target.value)}
                >
                  {branches.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name}
                    </option>
                  ))}
                </select>
              </Field>
            )}
            {form.data.delivery_enabled && (
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label={__('Delivery fee (R)')} error={errors.delivery_fee_cents}>
                  <Input
                    type="number"
                    min={0}
                    step={0.01}
                    value={form.data.delivery_fee_cents / 100}
                    onChange={(e) =>
                      form.setData('delivery_fee_cents', Math.round(Number(e.target.value) * 100))
                    }
                  />
                </Field>
                <Field
                  label={__('Where you deliver (optional)')}
                  hint={__('e.g. Soweto and Johannesburg CBD')}
                  error={errors.delivery_note}
                >
                  <Input
                    value={form.data.delivery_note}
                    maxLength={300}
                    onChange={(e) => form.setData('delivery_note', e.target.value)}
                  />
                </Field>
              </div>
            )}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Unpaid orders')}</CardTitle>
            <CardDescription>
              {__('How customers pay — card, instant EFT or bank transfer — is set under')}{' '}
              <a
                className="underline"
                href={`${window.location.pathname.split('/').slice(0, 2).join('/')}/settings/payments`}
              >
                {__('Settings → Payments')}
              </a>
              {__(
                '. Stock is kept for an order paid by bank transfer until you mark it paid, or for the time below.',
              )}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Field
              label={__('Keep stock for unpaid orders (hours)')}
              error={errors.offline_hold_hours}
            >
              <Input
                type="number"
                min={1}
                max={720}
                className="w-32"
                value={form.data.offline_hold_hours}
                onChange={(e) => form.setData('offline_hold_hours', Number(e.target.value))}
              />
            </Field>
          </CardContent>
        </Card>
        <Button type="submit" className="justify-self-start" loading={form.processing}>
          {__('Save shop settings')}
        </Button>
      </form>
    </AppShell>
  );
}
