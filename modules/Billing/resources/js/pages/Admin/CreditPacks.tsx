import { Head, router, useForm } from '@inertiajs/react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Props {
  packs: { id: string; name: string; credits: number; price: number; active: boolean }[];
}

export default function CreditPacks({ packs }: Props) {
  const form = useForm({ name: '', credits: '', price: '' });
  return (
    <AppShell title="Credit packs">
      <Head title="Credit packs" />
      <div className="grid max-w-4xl gap-8">
        <PageHeader
          title="Credit packs"
          description="What businesses can buy from Settings → Billing. Prices exclude VAT."
        />
        {packs.length > 0 && (
          <Table label="Credit packs">
            <TableHeader>
              <TableRow>
                <TableHead>Pack</TableHead>
                <TableHead className="text-right">Credits</TableHead>
                <TableHead className="text-right">Price (R)</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>
                  <span className="sr-only">Actions</span>
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
                    {p.active ? <Badge tone="success">On sale</Badge> : <Badge>Withdrawn</Badge>}
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
            <CardTitle>Add a pack</CardTitle>
            <CardDescription>
              Packs can’t be edited once sold; withdraw one and add a new one instead.
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
              <Field label="Name" error={form.errors.name} required>
                <Input
                  value={form.data.name}
                  onChange={(e) => form.setData('name', e.target.value)}
                />
              </Field>
              <Field label="Credits" error={form.errors.credits} required>
                <Input
                  type="number"
                  value={form.data.credits}
                  onChange={(e) => form.setData('credits', e.target.value)}
                />
              </Field>
              <Field label="Price excl. VAT (R)" error={form.errors.price} required>
                <Input
                  type="number"
                  step="0.01"
                  value={form.data.price}
                  onChange={(e) => form.setData('price', e.target.value)}
                />
              </Field>
              <Button type="submit" loading={form.processing}>
                Add pack
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
