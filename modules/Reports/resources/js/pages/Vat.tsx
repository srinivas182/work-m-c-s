import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/design-system/components/button';
import { Card, CardContent } from '@/design-system/components/card';
import { Checkbox } from '@/design-system/components/choice';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import { HelpTip } from '@/components/HelpTip';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

/** Settings → VAT registration: reports show VAT only for a VAT-registered business. */
export default function Vat({
  vat_registered,
  vat_number,
  rate,
}: {
  vat_registered: boolean;
  vat_number: string | null;
  rate: number;
}) {
  const form = useForm({ vat_registered, vat_number: vat_number ?? '' });
  return (
    <AppShell title={__('VAT registration')}>
      <Head title={__('VAT registration')} />
      <div className="grid max-w-2xl gap-6">
        <PageHeader
          title={__('VAT registration')}
          description={`If you’re registered for VAT, your reports show the VAT included in what you took (${rate}%).`}
        />
        <Card>
          <CardContent className="pt-6">
            <form
              aria-label={__('VAT registration')}
              className="grid gap-3 text-sm"
              onSubmit={(e) => {
                e.preventDefault();
                form.put(window.location.pathname, { preserveScroll: true });
              }}
            >
              <label className="flex items-center gap-2">
                <Checkbox
                  checked={form.data.vat_registered}
                  onCheckedChange={(on) => form.setData('vat_registered', on === true)}
                />
                {__('We’re registered for VAT')}{' '}
                <HelpTip>
                  {__(
                    'Only VAT-registered businesses charge VAT. Most small businesses aren’t until their sales pass R1 million a year — ask your accountant if you’re unsure.',
                  )}
                </HelpTip>
              </label>
              {form.data.vat_registered && (
                <Field label={__('VAT number')} error={form.errors.vat_number}>
                  <Input
                    value={form.data.vat_number}
                    onChange={(e) => form.setData('vat_number', e.target.value)}
                  />
                </Field>
              )}
              <p className="text-fg-muted">
                {__(
                  'VAT is worked out on prices that include it. Check with your accountant before filing.',
                )}
              </p>
              <Button type="submit" className="justify-self-start" loading={form.processing}>
                {__('Save')}
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
