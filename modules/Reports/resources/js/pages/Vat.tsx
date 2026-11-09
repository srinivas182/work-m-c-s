import { Head, useForm } from '@inertiajs/react';
import { Button, Card, CardContent, Checkbox, Field, Input, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';

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
    <AppShell title="VAT registration">
      <Head title="VAT registration" />
      <div className="grid max-w-2xl gap-6">
        <PageHeader
          title="VAT registration"
          description={`If you’re registered for VAT, your reports show the VAT included in what you took (${rate}%).`}
        />
        <Card>
          <CardContent className="pt-6">
            <form
              aria-label="VAT registration"
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
                We’re registered for VAT
              </label>
              {form.data.vat_registered && (
                <Field label="VAT number" error={form.errors.vat_number}>
                  <Input
                    value={form.data.vat_number}
                    onChange={(e) => form.setData('vat_number', e.target.value)}
                  />
                </Field>
              )}
              <p className="text-fg-muted">
                VAT is worked out on prices that include it. Check with your accountant before
                filing.
              </p>
              <Button type="submit" className="justify-self-start" loading={form.processing}>
                Save
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
