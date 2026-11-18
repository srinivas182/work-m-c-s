import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/design-system/components/card';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Props {
  issuer: { name: string; address: string; vat_number: string; registration: string };
  placeholder: boolean;
  dunning: {
    retry_days: number[];
    restrict_from_day: number;
    suspend_on_day: number;
    prepaid_grace_days: number;
  };
  refundDays: number;
}

export default function InvoiceSettings({ issuer, placeholder, dunning, refundDays }: Props) {
  const form = useForm({
    ...issuer,
    retry_days: dunning.retry_days.join(', '),
    restrict_from_day: dunning.restrict_from_day,
    suspend_on_day: dunning.suspend_on_day,
    prepaid_grace_days: dunning.prepaid_grace_days,
    refund_days: refundDays,
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
    <AppShell title={__('Invoice settings')}>
      <Head title={__('Invoice settings')} />
      <form onSubmit={submit} className="grid max-w-3xl gap-8" noValidate>
        <PageHeader
          title={__('Invoice settings')}
          description={__(
            'The supplier details printed on every tax invoice, and how WURK chases failed payments.',
          )}
        />
        {placeholder && (
          <div role="alert" className="border-danger-6 bg-danger-3 rounded-2xl border p-5 text-sm">
            <p className="font-semibold">{__('These are placeholder company details.')}</p>
            <p className="mt-1">
              {__(
                'Issued tax invoices can never be edited, so replace them with WURK’s registered name, address and VAT number before invoicing real customers.',
              )}
            </p>
          </div>
        )}
        <Card>
          <CardHeader>
            <CardTitle>{__('Supplier on invoices')}</CardTitle>
            <CardDescription>
              {__(
                'Changes apply to new invoices only; issued invoices keep the details they were issued with.',
              )}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-5 sm:grid-cols-2">
            <Field
              label={__('Registered name')}
              error={errors.name}
              required
              className="sm:col-span-2"
            >
              <Input
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
              />
            </Field>
            <Field label={__('Address')} error={errors.address} required className="sm:col-span-2">
              <Input
                value={form.data.address}
                onChange={(e) => form.setData('address', e.target.value)}
              />
            </Field>
            <Field label={__('VAT number')} error={errors.vat_number} required>
              <Input
                value={form.data.vat_number}
                onChange={(e) => form.setData('vat_number', e.target.value)}
                inputMode="numeric"
              />
            </Field>
            <Field label={__('Company registration')} error={errors.registration}>
              <Input
                value={form.data.registration}
                onChange={(e) => form.setData('registration', e.target.value)}
              />
            </Field>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Failed payments')}</CardTitle>
            <CardDescription>
              {__('Day 0 is the day a payment fails. Owners are told at once and on each retry.')}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-5 sm:grid-cols-3">
            <Field
              label={__('Retry on days')}
              hint={__('e.g. 3, 5, 7')}
              error={errors.retry_days ?? errors['retry_days.0']}
            >
              <Input
                value={form.data.retry_days}
                onChange={(e) => form.setData('retry_days', e.target.value)}
              />
            </Field>
            <Field label={__('Only Billing open from day')} error={errors.restrict_from_day}>
              <Input
                type="number"
                value={form.data.restrict_from_day}
                onChange={(e) => form.setData('restrict_from_day', Number(e.target.value))}
              />
            </Field>
            <Field label={__('Suspend on day')} error={errors.suspend_on_day}>
              <Input
                type="number"
                value={form.data.suspend_on_day}
                onChange={(e) => form.setData('suspend_on_day', Number(e.target.value))}
              />
            </Field>
            <Field
              label={__('Pay as you go: days a business keeps working with no credits')}
              error={(errors as Record<string, string | undefined>).prepaid_grace_days}
            >
              <Input
                type="number"
                min="0"
                value={form.data.prepaid_grace_days}
                onChange={(e) => form.setData('prepaid_grace_days', Number(e.target.value))}
              />
            </Field>
            <Field
              label={__('Refund window (days after payment; 0 = no refunds)')}
              error={(errors as Record<string, string | undefined>).refund_days}
            >
              <Input
                type="number"
                min="0"
                max="365"
                value={form.data.refund_days}
                onChange={(e) => form.setData('refund_days', Number(e.target.value))}
              />
            </Field>
            <p className="text-fg-muted text-sm">
              {__(
                'No credit notes once a payment’s window has closed. Agents’ commission on a payment becomes payable when it closes.',
              )}
            </p>
          </CardContent>
        </Card>
        <Button type="submit" size="lg" className="w-fit" loading={form.processing}>
          {__('Save settings')}
        </Button>
      </form>
    </AppShell>
  );
}
