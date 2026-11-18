import { Head, router, useForm } from '@inertiajs/react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/design-system/components/card';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system/components/table';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Props {
  packs: { id: string; name: string; credits: number; price: number; active: boolean }[];
}

export default function CreditPacks({ packs }: Props) {
  const form = useForm({ name: '', credits: '', price: '' });
  return (
    <AppShell title={__('Credit packs')}>
      <Head title={__('Credit packs')} />
      <div className="grid max-w-4xl gap-8">
        <PageHeader
          title={__('Credit packs')}
          description={__('What businesses can buy from Settings → Billing. Prices exclude VAT.')}
        />
        {packs.length > 0 && (
          <Table label={__('Credit packs')}>
            <TableHeader>
              <TableRow>
                <TableHead>{__('Pack')}</TableHead>
                <TableHead className="text-right">{__('Credits')}</TableHead>
                <TableHead className="text-right">{__('Price (R)')}</TableHead>
                <TableHead>{__('Status')}</TableHead>
                <TableHead>
                  <span className="sr-only">{__('Actions')}</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {packs.map((p) => (
                <TableRow key={p.id}>
                  <TableCell className="font-medium">{p.name}</TableCell>
                  <TableCell className="text-right tabular-nums">
                    {p.credits.toLocaleString()}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{p.price.toFixed(2)}</TableCell>
                  <TableCell>
                    {p.active ? (
                      <Badge tone="success">{__('On sale')}</Badge>
                    ) : (
                      <Badge>{__('Withdrawn')}</Badge>
                    )}
                  </TableCell>
                  <TableCell>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() =>
                        router.post(`/credits/packs/${p.id}/toggle`, {}, { preserveScroll: true })
                      }
                    >
                      {p.active ? 'Withdraw' : 'Put on sale'}
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
        <Card>
          <CardHeader>
            <CardTitle>{__('Add a pack')}</CardTitle>
            <CardDescription>
              {__('Packs can’t be edited once sold; withdraw one and add a new one instead.')}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form
              onSubmit={(e) => {
                e.preventDefault();
                form.post('/credits/packs', {
                  preserveScroll: true,
                  onSuccess: () => form.reset(),
                });
              }}
              className="grid grid-cols-1 items-end gap-4 sm:grid-cols-[1.5fr_1fr_1fr_auto]"
            >
              <Field label={__('Name')} error={form.errors.name} required>
                <Input
                  value={form.data.name}
                  onChange={(e) => form.setData('name', e.target.value)}
                />
              </Field>
              <Field label={__('Credits')} error={form.errors.credits} required>
                <Input
                  type="number"
                  value={form.data.credits}
                  onChange={(e) => form.setData('credits', e.target.value)}
                />
              </Field>
              <Field label={__('Price excl. VAT (R)')} error={form.errors.price} required>
                <Input
                  type="number"
                  step="0.01"
                  value={form.data.price}
                  onChange={(e) => form.setData('price', e.target.value)}
                />
              </Field>
              <Button type="submit" loading={form.processing}>
                {__('Add pack')}
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
