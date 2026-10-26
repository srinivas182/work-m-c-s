import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  EmptyState,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { Package } from 'lucide-react';

interface VariantRow {
  id?: string;
  values: Record<string, string>;
  sku: string | null;
  price_cents: number;
  compare_at_cents: number | null;
  stock: number | null;
}
interface ProductRow {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  category_id: string | null;
  status: 'draft' | 'published';
  options: string[];
  photos: string[];
  variants: VariantRow[];
}
interface Props {
  categories: { id: string; name: string }[];
  products: ProductRow[];
}

const base = () => window.location.pathname.replace(/\/products\/?$/, '');
const selectClass = 'h-10 w-full rounded-md border border-border bg-bg px-3 text-sm';
const rand = (c: number) => `R${(c / 100).toFixed(c % 100 ? 2 : 0)}`;
const blankVariant = (options: string[]): VariantRow => ({
  values: Object.fromEntries(options.map((o) => [o, ''])),
  sku: '',
  price_cents: 0,
  compare_at_cents: null,
  stock: null,
});

function Editor({
  product,
  categories,
  onDone,
}: {
  product: ProductRow | null;
  categories: Props['categories'];
  onDone: () => void;
}) {
  const form = useForm({
    name: product?.name ?? '',
    description: product?.description ?? '',
    category_id: product?.category_id ?? '',
    status: product?.status ?? 'draft',
    options: product?.options ?? [],
    photos: product?.photos ?? [],
    variants: product?.variants.length ? product.variants : [blankVariant([])],
  });
  const errors = form.errors as Record<string, string | undefined>;
  const setOptions = (options: string[]) =>
    form.setData((d) => ({
      ...d,
      options,
      variants: d.variants.map((v) => ({
        ...v,
        values: Object.fromEntries(options.map((o) => [o, v.values[o] ?? ''])),
      })),
    }));
  const setVariant = (i: number, patch: Partial<VariantRow>) =>
    form.setData(
      'variants',
      form.data.variants.map((v, j) => (j === i ? { ...v, ...patch } : v)),
    );
  const cents = (s: string) => (s === '' ? null : Math.round(Number(s) * 100));
  return (
    <Card>
      <CardHeader>
        <CardTitle>{product ? `Edit ${product.name}` : 'Add a product'}</CardTitle>
        <CardDescription>
          Prices include VAT. Leave stock empty if you don’t track it.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form
          aria-label={product ? `Edit ${product.name}` : 'Add a product'}
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            form.transform((d) => ({ ...d, category_id: d.category_id || null }));
            const opts = { preserveScroll: true, onSuccess: onDone };
            if (product) form.put(`${base()}/products/${product.id}`, opts);
            else form.post(`${base()}/products`, opts);
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Name" error={errors.name}>
              <Input
                value={form.data.name}
                maxLength={120}
                onChange={(e) => form.setData('name', e.target.value)}
              />
            </Field>
            <Field label="Category" error={errors.category_id}>
              <select
                className={selectClass}
                value={form.data.category_id}
                onChange={(e) => form.setData('category_id', e.target.value)}
              >
                <option value="">None</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </Field>
          </div>
          <Field label="Description" error={errors.description}>
            <textarea
              className="border-border bg-bg min-h-24 w-full rounded-md border p-3 text-sm"
              maxLength={5000}
              value={form.data.description}
              onChange={(e) => form.setData('description', e.target.value)}
            />
          </Field>
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium">Options (e.g. Size, Colour)</legend>
            <div className="flex flex-wrap items-end gap-2">
              {form.data.options.map((o, i) => (
                <span key={i} className="flex items-end gap-1">
                  <Input
                    aria-label={`Option ${i + 1} name`}
                    className="w-36"
                    value={o}
                    maxLength={30}
                    onChange={(e) =>
                      setOptions(form.data.options.map((x, j) => (j === i ? e.target.value : x)))
                    }
                  />
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    aria-label={`Remove option ${o || i + 1}`}
                    onClick={() => setOptions(form.data.options.filter((_, j) => j !== i))}
                  >
                    Remove
                  </Button>
                </span>
              ))}
              {form.data.options.length < 3 && (
                <Button
                  type="button"
                  size="sm"
                  variant="secondary"
                  onClick={() =>
                    setOptions([
                      ...form.data.options,
                      form.data.options.length === 0
                        ? 'Size'
                        : form.data.options.length === 1
                          ? 'Colour'
                          : 'Material',
                    ])
                  }
                >
                  Add an option
                </Button>
              )}
            </div>
            {errors['options.0'] && <p className="text-danger text-sm">{errors['options.0']}</p>}
          </fieldset>
          <fieldset className="grid gap-3">
            <legend className="mb-1 text-sm font-medium">
              {form.data.options.length ? 'Variants' : 'Price and stock'}
            </legend>
            {form.data.variants.map((v, i) => (
              <div
                key={v.id ?? i}
                className="border-border grid gap-2 rounded-lg border p-3 sm:grid-cols-6 sm:items-end"
              >
                {form.data.options.map((o) => (
                  <Field key={o} label={o || 'Option'} error={errors[`variants.${i}.values`]}>
                    <Input
                      value={v.values[o] ?? ''}
                      maxLength={40}
                      onChange={(e) =>
                        setVariant(i, { values: { ...v.values, [o]: e.target.value } })
                      }
                    />
                  </Field>
                ))}
                <Field label="Price (R)" error={errors[`variants.${i}.price_cents`]}>
                  <Input
                    type="number"
                    min={0}
                    step={0.01}
                    value={v.price_cents / 100}
                    onChange={(e) => setVariant(i, { price_cents: cents(e.target.value) ?? 0 })}
                  />
                </Field>
                <Field label="Was (R)" error={errors[`variants.${i}.compare_at_cents`]}>
                  <Input
                    type="number"
                    min={0}
                    step={0.01}
                    value={v.compare_at_cents === null ? '' : v.compare_at_cents / 100}
                    onChange={(e) => setVariant(i, { compare_at_cents: cents(e.target.value) })}
                  />
                </Field>
                <Field label="SKU">
                  <Input
                    value={v.sku ?? ''}
                    maxLength={60}
                    onChange={(e) => setVariant(i, { sku: e.target.value })}
                  />
                </Field>
                <Field label="Stock" error={errors[`variants.${i}.stock`]}>
                  <Input
                    type="number"
                    min={0}
                    value={v.stock ?? ''}
                    onChange={(e) =>
                      setVariant(i, {
                        stock: e.target.value === '' ? null : Number(e.target.value),
                      })
                    }
                  />
                </Field>
                {form.data.variants.length > 1 && (
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    aria-label={`Remove variant ${i + 1}`}
                    onClick={() =>
                      form.setData(
                        'variants',
                        form.data.variants.filter((_, j) => j !== i),
                      )
                    }
                  >
                    Remove
                  </Button>
                )}
              </div>
            ))}
            {form.data.options.length > 0 && (
              <Button
                type="button"
                size="sm"
                variant="secondary"
                className="justify-self-start"
                onClick={() =>
                  form.setData('variants', [...form.data.variants, blankVariant(form.data.options)])
                }
              >
                Add a variant
              </Button>
            )}
          </fieldset>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.data.status === 'published'}
              onChange={(e) => form.setData('status', e.target.checked ? 'published' : 'draft')}
            />
            Show in the shop
          </label>
          <span className="flex gap-2">
            <Button type="submit" loading={form.processing}>
              Save product
            </Button>
            <Button type="button" variant="ghost" onClick={onDone}>
              Cancel
            </Button>
          </span>
        </form>
      </CardContent>
    </Card>
  );
}

export default function Products({ categories, products }: Props) {
  const [editing, setEditing] = useState<ProductRow | 'new' | null>(null);
  const category = useForm({ name: '' });
  return (
    <AppShell title="Products">
      <Head title="Products" />
      <div className="grid max-w-5xl gap-6">
        <PageHeader
          title="Products"
          description="What you sell online. Customers buy from your website; payments go straight to your bank."
          actions={
            editing ? undefined : <Button onClick={() => setEditing('new')}>Add a product</Button>
          }
        />
        {editing && (
          <Editor
            product={editing === 'new' ? null : editing}
            categories={categories}
            onDone={() => setEditing(null)}
          />
        )}
        {products.length === 0 && !editing ? (
          <EmptyState
            icon={Package}
            title="No products yet"
            description="Add your first product to start selling."
          />
        ) : (
          <ul className="grid gap-2" aria-label="Products">
            {products.map((p) => {
              const prices = p.variants.map((v) => v.price_cents);
              const tracked = p.variants.filter((v) => v.stock !== null);
              const stock = tracked.reduce((n, v) => n + (v.stock ?? 0), 0);
              return (
                <li
                  key={p.id}
                  className="border-border flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                >
                  <div>
                    <p className="font-medium">
                      {p.name}{' '}
                      <Badge tone={p.status === 'published' ? 'success' : 'neutral'}>
                        {p.status === 'published' ? 'In the shop' : 'Draft'}
                      </Badge>
                    </p>
                    <p className="text-fg-muted text-sm">
                      {Math.min(...prices) === Math.max(...prices)
                        ? rand(prices[0] ?? 0)
                        : `${rand(Math.min(...prices))}–${rand(Math.max(...prices))}`}
                      {p.variants.length > 1 ? ` · ${p.variants.length} variants` : ''}
                      {tracked.length > 0
                        ? stock > 0
                          ? ` · ${stock} in stock`
                          : ' · Out of stock'
                        : ''}
                    </p>
                  </div>
                  <span className="flex gap-1">
                    <Button
                      size="sm"
                      variant="secondary"
                      aria-label={`Edit ${p.name}`}
                      onClick={() => setEditing(p)}
                    >
                      Edit
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      aria-label={`Delete ${p.name}`}
                      onClick={() =>
                        router.delete(`${base()}/products/${p.id}`, { preserveScroll: true })
                      }
                    >
                      Delete
                    </Button>
                  </span>
                </li>
              );
            })}
          </ul>
        )}
        <Card>
          <CardHeader>
            <CardTitle>Categories</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3">
            <ul className="flex flex-wrap gap-2" aria-label="Categories">
              {categories.map((c) => (
                <li
                  key={c.id}
                  className="border-border flex items-center gap-1 rounded-full border px-3 py-1 text-sm"
                >
                  {c.name}
                  <Button
                    size="sm"
                    variant="ghost"
                    aria-label={`Remove category ${c.name}`}
                    onClick={() =>
                      router.delete(`${base()}/categories/${c.id}`, { preserveScroll: true })
                    }
                  >
                    Remove
                  </Button>
                </li>
              ))}
            </ul>
            <form
              aria-label="Add a category"
              className="flex flex-wrap items-end gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                category.post(`${base()}/categories`, {
                  preserveScroll: true,
                  onSuccess: () => category.reset(),
                });
              }}
            >
              <Field label="New category" error={category.errors.name}>
                <Input
                  value={category.data.name}
                  maxLength={80}
                  onChange={(e) => category.setData('name', e.target.value)}
                />
              </Field>
              <Button type="submit" variant="secondary" loading={category.processing}>
                Add category
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
