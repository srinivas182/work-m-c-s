import { Head, useForm } from '@inertiajs/react';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Checkbox,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Settings {
  collection_enabled: boolean;
  collection_address: string;
  delivery_enabled: boolean;
  delivery_fee_cents: number;
  delivery_note: string;
  offline_enabled: boolean;
  offline_instructions: string;
  offline_hold_hours: number;
}

const area = 'min-h-24 w-full rounded-md border border-border bg-bg p-3 text-sm';

export default function ShopSettings({ settings }: { settings: Settings }) {
  const form = useForm<Settings>({ ...settings });
  const errors = form.errors as Record<string, string | undefined>;
  return (
    <AppShell title="Shop settings">
      <Head title="Shop settings" />
      <form
        aria-label="Shop settings"
        className="grid max-w-3xl gap-6"
        onSubmit={(e) => {
          e.preventDefault();
          form.put(window.location.pathname, { preserveScroll: true });
        }}
      >
        <PageHeader
          title="Shop settings"
          description="How customers get their orders and how they pay. Prices include VAT; WURK takes no fee on your sales."
        />
        <Card>
          <CardHeader>
            <CardTitle>Collection and delivery</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4">
            <label className="flex items-center gap-2 text-sm font-medium">
              <Checkbox
                checked={form.data.collection_enabled}
                onCheckedChange={(on) => form.setData('collection_enabled', on === true)}
              />
              Customers can collect
            </label>
            {errors.collection_enabled && (
              <p className="text-danger text-sm">{errors.collection_enabled}</p>
            )}
            {form.data.collection_enabled && (
              <Field label="Collection address" error={errors.collection_address}>
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
              We deliver
            </label>
            {form.data.delivery_enabled && (
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Delivery fee (R)" error={errors.delivery_fee_cents}>
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
                  label="Where you deliver (optional)"
                  hint="e.g. Soweto and Johannesburg CBD"
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
            <CardTitle>Paying by EFT or on collection</CardTitle>
            <CardDescription>
              Customers order now and pay you directly. Stock is kept for them until you mark the
              order paid — or it’s released after the time below.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4">
            <label className="flex items-center gap-2 text-sm font-medium">
              <Checkbox
                checked={form.data.offline_enabled}
                onCheckedChange={(on) => form.setData('offline_enabled', on === true)}
              />
              Accept orders paid by EFT or on collection
            </label>
            {form.data.offline_enabled && (
              <>
                <Field
                  label="How to pay"
                  hint="Shown to customers with their order number as the reference."
                  error={errors.offline_instructions}
                >
                  <textarea
                    className={area}
                    maxLength={2000}
                    placeholder={'e.g. FNB, account 62000000000, branch 250655'}
                    value={form.data.offline_instructions}
                    onChange={(e) => form.setData('offline_instructions', e.target.value)}
                  />
                </Field>
                <Field
                  label="Keep stock for unpaid orders (hours)"
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
              </>
            )}
          </CardContent>
        </Card>
        <Button type="submit" className="justify-self-start" loading={form.processing}>
          Save shop settings
        </Button>
      </form>
    </AppShell>
  );
}
