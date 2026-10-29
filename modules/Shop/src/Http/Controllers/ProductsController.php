<?php

declare(strict_types=1);

namespace Wurk\Shop\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopCategory;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\BranchStock;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\CatalogChanged;

/** Owners: Shop → Products — products with options and variants (own price, SKU and stock), and categories. */
final class ProductsController
{
    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    public function index(): Response
    {
        $variants = Variant::query()->orderBy('position')->get()->groupBy('product_id');

        return Inertia::render('Shop::Products', [
            'categories' => ShopCategory::query()->orderBy('position')->orderBy('name')->get(['id', 'name'])->all(),
            'products' => Product::query()->orderBy('position')->orderBy('name')->get()->map(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'description' => $p->description, 'category_id' => $p->category_id,
                'status' => $p->status, 'options' => $p->options ?? [], 'photos' => $p->photos ?? [],
                'variants' => ($variants[$p->id] ?? collect())->map(fn (Variant $v) => [
                    'id' => $v->id, 'values' => $v->values ?? (object) [], 'sku' => $v->sku, 'price_cents' => $v->price_cents,
                    'compare_at_cents' => $v->compare_at_cents, 'stock' => $v->stock,
                ])->values()->all(),
            ])->all(),
        ]);
    }

    public function save(Request $request, ?string $product = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'string', Rule::exists($this->context->connectionName().'.shop_categories', 'id')],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'options' => ['present', 'array', 'max:3'],
            'options.*' => ['required', 'string', 'max:30', 'distinct'],
            'photos' => ['present', 'array', 'max:10'],
            'photos.*' => ['string', 'max:500'],
            'variants' => ['required', 'array', 'min:1', 'max:100'],
            'variants.*.id' => ['nullable', 'string'],
            'variants.*.values' => ['present', 'array'],
            'variants.*.sku' => ['nullable', 'string', 'max:60'],
            'variants.*.price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'variants.*.compare_at_cents' => ['nullable', 'integer', 'max:100000000'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'variants.*.stock_at' => ['nullable', 'array'],            // several branches: stock per branch
            'variants.*.stock_at.*' => ['integer', 'min:0', 'max:1000000'],
            'variants.*.prices' => ['nullable', 'array'],              // several branches: optional price per branch
            'variants.*.prices.*' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ], ['variants.min' => 'Add at least one price (a variant).', 'options.*.distinct' => 'Each option needs a different name.']);

        $options = array_values($data['options']);
        $seen = [];
        foreach ($data['variants'] as $i => $v) {
            $values = array_map('strval', (array) $v['values']);
            $given = array_keys($values);
            $wanted = $options;
            sort($given);
            sort($wanted);
            if ($given !== $wanted || in_array('', array_map('trim', $values), true)) { // any order, every option
                throw ValidationException::withMessages(["variants.{$i}.values" => 'Give every option a value ('.implode(', ', $options).').']);
            }
            $key = mb_strtolower(implode('|', $values));
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["variants.{$i}.values" => 'Two variants have the same '.implode(' and ', $options).'.']);
            }
            $seen[$key] = true;
            if (($v['compare_at_cents'] ?? null) !== null && $v['compare_at_cents'] <= $v['price_cents']) {
                throw ValidationException::withMessages(["variants.{$i}.compare_at_cents" => 'The “was” price must be higher than the price.']);
            }
        }

        $saved = DB::connection($this->context->connectionName())->transaction(function () use ($data, $options, $product): Product {
            $model = $product === null ? new Product : Product::query()->whereKey($product)->firstOrFail();
            if ($model->name !== $data['name'] || ! $model->exists) {
                $model->slug = $this->uniqueSlug($data['name'], $model->exists ? $model->id : null);
            }
            $model->fill(['name' => $data['name'], 'description' => $data['description'] ?? null, 'category_id' => $data['category_id'] ?? null,
                'status' => $data['status'], 'options' => $options, 'photos' => array_values($data['photos'])])->save();

            $keep = [];
            foreach (array_values($data['variants']) as $position => $v) {
                $given = (array) $v['values'];
                $values = $options === [] ? null : array_combine($options, array_map(fn (string $o) => (string) $given[$o], $options)); // stored in option order
                $attrs = ['product_id' => $model->id, 'values' => $values, 'label' => $values ? implode(' / ', $values) : null, 'sku' => $v['sku'] ?? null,
                    'price_cents' => $v['price_cents'], 'compare_at_cents' => $v['compare_at_cents'] ?? null, 'position' => $position];
                $variant = ! empty($v['id']) ? Variant::query()->where('product_id', $model->id)->whereKey($v['id'])->first() : null;
                $variant ? $variant->update($attrs) : $variant = Variant::query()->create($attrs);
                // Stock per branch (several branches), or the total at the main branch; the variant's total follows.
                $open = array_column(app(BranchDirectory::class)->open(), 'id');
                $perBranch = isset($v['stock_at']) ? array_intersect_key(array_map('intval', (array) $v['stock_at']), array_flip($open)) : null;
                app(BranchStock::class)->set($variant, $perBranch ?? (($v['stock'] ?? null) === null ? null : [app(BranchDirectory::class)->main() => (int) $v['stock']]));
                app(BranchStock::class)->setPrices($variant, array_intersect_key((array) ($v['prices'] ?? []), array_flip($open)));
                $keep[] = $variant->id;
            }
            Variant::query()->where('product_id', $model->id)->whereNotIn('id', $keep)->delete();

            return $model;
        });
        $this->audit->record($product === null ? 'shop.product_created' : 'shop.product_updated', 'product', $saved->id, ['name' => $saved->name]);
        event(new CatalogChanged($this->context->id())); // pages with shop sections are re-rendered

        return back()->with('status', 'Product saved.');
    }

    public function destroy(string $product): RedirectResponse
    {
        $model = Product::query()->whereKey($product)->firstOrFail();
        DB::connection($this->context->connectionName())->transaction(function () use ($model): void {
            Variant::query()->where('product_id', $model->id)->delete();
            $model->delete();
        });
        $this->audit->record('shop.product_deleted', 'product', $model->id, ['name' => $model->name]);
        event(new CatalogChanged($this->context->id()));

        return back()->with('status', 'Product deleted.');
    }

    public function addCategory(Request $request): RedirectResponse
    {
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:80']])['name'];
        $slug = Str::slug($name) ?: 'category';
        if (ShopCategory::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['name' => 'You already have a category with that name.']);
        }
        ShopCategory::query()->create(['name' => $name, 'slug' => $slug, 'position' => (int) ShopCategory::query()->max('position') + 1]);

        return back()->with('status', 'Category added.');
    }

    public function removeCategory(string $category): RedirectResponse
    {
        Product::query()->where('category_id', $category)->update(['category_id' => null]);
        ShopCategory::query()->whereKey($category)->delete();

        return back()->with('status', 'Category removed. Its products are kept.');
    }

    private function uniqueSlug(string $name, ?string $except): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        for ($n = 2; Product::query()->where('slug', $slug)->when($except, fn ($q) => $q->whereKeyNot($except))->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
