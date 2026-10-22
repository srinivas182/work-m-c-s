import { Head, useForm } from '@inertiajs/react';
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
import { CreditOverview, type CreditSummary } from './Summary';

export default function Business(summary: CreditSummary) {
  const form = useForm({ low_balance: summary.lowBalance });
  return (
    <AppShell title="WURK Credits">
      <Head title="WURK Credits" />
      <div className="grid max-w-5xl gap-8">
        <PageHeader
          title="WURK Credits"
          description="One balance for SMS, WhatsApp, email and AI. Failed messages are refunded automatically."
        />
        <CreditOverview summary={summary} />
        <Card>
          <CardHeader>
            <CardTitle>Low-balance alert</CardTitle>
            <CardDescription>
              We’ll let you know when your balance drops below this. Buying credits arrives with
              billing.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form
              onSubmit={(e) => {
                e.preventDefault();
                form.put(window.location.pathname + '/alert', { preserveScroll: true });
              }}
              className="flex flex-col gap-3 sm:flex-row sm:items-end"
            >
              <Field
                label="Alert below (credits)"
                error={form.errors.low_balance}
                className="sm:w-56"
              >
                <Input
                  type="number"
                  min={0}
                  value={form.data.low_balance}
                  onChange={(e) => form.setData('low_balance', Number(e.target.value))}
                />
              </Field>
              <Button type="submit" loading={form.processing}>
                Save
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
