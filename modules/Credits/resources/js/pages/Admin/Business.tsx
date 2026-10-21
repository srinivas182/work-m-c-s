import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
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
import { CreditOverview, type CreditSummary } from '../Summary';

type Props = CreditSummary & { business: { id: string; name: string }; canAdjust: boolean };

export default function Business({ business, canAdjust, ...summary }: Props) {
  const form = useForm({ credits: '', reason: '' });
  return (
    <AppShell title={`${business.name} — credits`}>
      <Head title={`${business.name} — credits`} />
      <div className="grid max-w-5xl gap-8">
        <Link
          href={`/businesses/${business.id}`}
          className="text-fg-muted hover:text-fg inline-flex w-fit items-center gap-1.5 text-sm font-medium"
        >
          <ArrowLeft className="size-4" aria-hidden /> {business.name}
        </Link>
        <PageHeader title="WURK Credits" eyebrow={business.name} />
        <CreditOverview summary={summary} />
        {canAdjust && (
          <Card>
            <CardHeader>
              <CardTitle>Adjust balance</CardTitle>
              <CardDescription>
                Goodwill credits or corrections. Use a negative number to remove credits. Recorded
                in the audit log.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  form.post(`/businesses/${business.id}/credits`, {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                  });
                }}
                className="grid grid-cols-1 items-end gap-4 sm:grid-cols-[10rem_1fr_auto]"
              >
                <Field label="Credits" error={form.errors.credits} required>
                  <Input
                    type="number"
                    step="0.1"
                    value={form.data.credits}
                    onChange={(e) => form.setData('credits', e.target.value)}
                  />
                </Field>
                <Field label="Reason" error={form.errors.reason} required>
                  <Input
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                  />
                </Field>
                <Button type="submit" loading={form.processing}>
                  Apply
                </Button>
              </form>
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
