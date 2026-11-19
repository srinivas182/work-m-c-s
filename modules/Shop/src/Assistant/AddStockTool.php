<?php

declare(strict_types=1);

namespace Wurk\Shop\Assistant;

use Illuminate\Support\Facades\DB;
use Wurk\Access\Contracts\BranchRestriction;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Platform\Contracts\AssistantActionTool;
use Wurk\Platform\Contracts\AssistantRefused;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\BranchStock;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Ask WURK (26.1): "add 20 Kasi T-shirts, size M". Adds stock to one product's variant at a branch (the main one
 * unless named), as the product editor does. Undo takes the same number back, if it's still there.
 */
final readonly class AddStockTool implements AssistantActionTool
{
    public function __construct(private TenantContext $context, private BranchStock $stock, private BranchDirectory $branches, private BranchRestriction $restriction,
        private AuditLog $audit) {}

    public function name(): string
    {
        return 'add_stock';
    }

    public function description(): string
    {
        return 'Add stock that has arrived to a product (and its size or colour, if it has them).';
    }

    public function parameters(): array
    {
        return ['product' => 'the product’s name', 'variant' => 'the size, colour or other option, if any', 'quantity' => 'how many arrived (a whole number)',
            'branch' => 'the branch (only if the business has several)'];
    }

    public function permission(): string
    {
        return 'shop.manage';
    }

    public function entitlement(): string
    {
        return 'shop';
    }

    public function preview(array $params): array
    {
        $p = $this->check($params);

        return ['summary' => "Add {$p['quantity']} × {$p['label']}".($p['branch'] !== null ? " at {$p['branch']}" : ''),
            'changes' => ["In stock: {$p['have']} → ".($p['have'] + $p['quantity'])]];
    }

    public function apply(array $params): array
    {
        $p = $this->check($params);
        DB::connection($this->context->connectionName())->transaction(fn () => $this->stock->give($p['variant'], $p['branch_id'], $p['quantity']));
        $this->audit->record('shop.stock_added', 'variant', $p['variant']->id, ['quantity' => $p['quantity'], 'branch' => $p['branch_id'], 'via' => 'Ask WURK']);

        return ['done' => "Added {$p['quantity']} × {$p['label']}. In stock now: ".($p['have'] + $p['quantity']).'.', 'link' => route('tenant.shop.products'),
            'undo' => ['variant_id' => $p['variant']->id, 'branch_id' => $p['branch_id'], 'quantity' => $p['quantity']]];
    }

    public function undo(array $undo): bool
    {
        $variant = Variant::query()->find((string) ($undo['variant_id'] ?? ''));
        if ($variant === null) {
            return false;
        }

        return DB::connection($this->context->connectionName())->transaction(fn () => $this->stock->take($variant, (string) $undo['branch_id'], (int) $undo['quantity'])) === null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{variant: Variant, label: string, quantity: int, have: int, branch_id: string, branch: string|null}
     */
    private function check(array $params): array
    {
        $quantity = (int) ($params['quantity'] ?? 0);
        if ($quantity < 1 || $quantity > 100000) {
            throw new AssistantRefused('How many arrived?');
        }
        $needle = mb_strtolower(trim((string) ($params['product'] ?? '')));
        $products = Product::query()->get(['id', 'name']);
        $exact = $products->filter(fn (Product $p) => mb_strtolower($p->name) === $needle);
        $found = $exact->isNotEmpty() ? $exact : $products->filter(fn (Product $p) => $needle !== '' && str_contains(mb_strtolower($p->name), $needle));
        if ($found->count() !== 1) {
            throw new AssistantRefused($found->isEmpty() ? 'I can’t find that product. What is it called in your shop?' : 'Which one — '.$found->pluck('name')->take(5)->implode(' or ').'?');
        }
        $product = $found->first() ?? throw new AssistantRefused('Which product?');
        $variants = Variant::query()->where('product_id', $product->id)->orderBy('position')->get();
        $option = mb_strtolower(trim((string) ($params['variant'] ?? '')));
        $matching = $option === '' ? $variants : $variants->filter(fn (Variant $v) => in_array($option, array_map('mb_strtolower', [...array_values((array) $v->values), (string) $v->label]), true));
        if ($matching->count() !== 1) {
            throw new AssistantRefused($matching->isEmpty() ? "{$product->name} has no “{$params['variant']}”." : 'Which one — '.$matching->map(fn (Variant $v) => $v->label ?? implode(' ', (array) $v->values))->take(6)->implode(', ').'?');
        }
        $variant = $matching->first() ?? throw new AssistantRefused('Which one?');
        if ($variant->stock === null) {
            throw new AssistantRefused("{$product->name} isn’t stock-counted. Switch on stock counting for it in Products first.");
        }
        $allowed = $this->restriction->branchRestriction((string) auth()->id());
        $open = array_values(array_filter($this->branches->open(), fn (array $b) => $allowed === null || in_array($b['id'], $allowed, true)));
        $name = mb_strtolower(trim((string) ($params['branch'] ?? '')));
        $branch = $name === '' ? (array_values(array_filter($open, fn (array $b) => $b['main']))[0] ?? $open[0] ?? null)
            : (array_values(array_filter($open, fn (array $b) => mb_strtolower($b['name']) === $name))[0] ?? null);
        if ($branch === null) {
            throw new AssistantRefused('Which branch?');
        }
        $label = trim($product->name.' '.($variants->count() > 1 ? ($variant->label ?? implode(' ', (array) $variant->values)) : ''));

        return ['variant' => $variant, 'label' => $label, 'quantity' => $quantity, 'have' => (int) $this->stock->at($variant, $branch['id']), 'branch_id' => $branch['id'],
            'branch' => $this->branches->several() ? $branch['name'] : null];
    }
}
