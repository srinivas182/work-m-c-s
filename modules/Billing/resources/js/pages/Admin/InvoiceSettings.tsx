import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Props {
  issuer: { name: string; address: string; vat_number: string; registration: string };
  placeholder: boolean;
  dunning: { retry_days: number[]; restrict_from_day: number; suspend_on_day: number };
}

export default function InvoiceSettings({ issuer, placeholder, dunning }: Props) {
  const form = useForm({
    ...issuer,
    retry_days: dunning.retry_days.join(', '),
    restrict_from_day: dunning.restrict_from_day,
    suspend_on_day: dunning.suspend_on_day,
  });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.transform((d) => ({
      ...d,
      retry_days: d.retry_days
        .split(/[,\s]+/)
        .filter(Boolean)
        .map(Number),
    }));
    form.put('/billing/settings', { preserveScroll: true });
  };
  const errors = form.errors as Record<string, string>;

  return (
    <AppShell title="Invoice settings">
      <Head title="Invoice settings" />
      <form onSubmit={submit} className="grid max-w-3xl gap-8" noValidate>
        <PageHeader
          title="Invoice settings"
          description="The supplier details printed on every tax invoice, and how WURK chases failed payments."
        />
        {placeholder && (
          <div role="alert" className="border-danger-6 bg-danger-3 rounded-2xl border p-5 text-sm">
            <p className="font-semibold">These are placeholder company details.</p>
            <p className="mt-1">
              Issued tax invoices can never be edited, so replace them with WURK’s registered name,
              address and VAT number before invoicing real customers.
            </p>
          </div>
        )}
        <Card>
          <CardHeader>
            <CardTitle>Supplier on invoices</CardTitle>
            <CardDescription>
              Changes apply to new invoices only; issued invoices keep the details they were issued
              with.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-5 sm:grid-cols-2">
            <Field label="Registered name" error={errors.name} required className="sm:col-span-2">
              <Input
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
              />
            </Field>
            <Field label="Address" error={errors.address} required className="sm:col-span-2">
              <Input
                value={form.data.address}
                onChange={(e) => form.setData('address', e.target.value)}
              />
            </Field>
            <Field label="VAT number" error={errors.vat_number} required>
              <Input
                value={form.data.vat_number}
                onChange={(e) => form.setData('vat_number', e.target.value)}
                inputMode="numeric"
              />
            </Field>
            <Field label="Company registration" error={errors.registration}>
              <Input
                value={form.data.registration}
                onChange={(e) => form.setData('registration', e.target.value)}
              />
            </Field>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Failed payments</CardTitle>
            <CardDescription>
              Day 0 is the day a payment fails. Owners are told at once and on each retry.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-5 sm:grid-cols-3">
            <Field
              label="Retry on days"
              hint="e.g. 3, 5, 7"
              error={errors.retry_days ?? errors['retry_days.0']}
            >
              <Input
                value={form.data.retry_days}
                onChange={(e) => form.setData('retry_days', e.target.value)}
              />
            </Field>
            <Field label="Only Billing open from day" error={errors.restrict_from_day}>
              <Input
                type="number"
                value={form.data.restrict_from_day}
                onChange={(e) => form.setData('restrict_from_day', Number(e.target.value))}
              />
            </Field>
            <Field label="Suspend on day" error={errors.suspend_on_day}>
              <Input
                type="number"
                value={form.data.suspend_on_day}
                onChange={(e) => form.setData('suspend_on_day', Number(e.target.value))}
              />
            </Field>
          </CardContent>
        </Card>
        <Button type="submit" size="lg" className="w-fit" loading={form.processing}>
          Save settings
        </Button>
      </form>
    </AppShell>
  );
}
