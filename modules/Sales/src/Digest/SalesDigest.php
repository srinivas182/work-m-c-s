<?php

declare(strict_types=1);

namespace Wurk\Sales\Digest;

use Carbon\CarbonImmutable;
use Wurk\Platform\Contracts\OwnerDigestRefused;
use Wurk\Platform\Contracts\OwnerDigestSource;
use Wurk\Platform\Money\Money;
use Wurk\Sales\Models\Sale;
use Wurk\Sales\Support\SaleProblem;
use Wurk\Sales\Support\Sales;

/** The owner's evening summary (21.6): today's sales, and large sales waiting for the owner's approval. */
final readonly class SalesDigest implements OwnerDigestSource
{
    public function __construct(private Sales $sales) {}

    public function key(): string
    {
        return 'sale';
    }

    public function figures(CarbonImmutable $today): array
    {
        return ['received_cents' => (int) Sale::query()->where('status', 'recorded')->where('recorded_at', '>=', $today->utc())->where('recorded_at', '<', $today->addDay()->utc())->sum('amount_cents')];
    }

    public function requests(): array
    {
        return array_values(Sale::query()->where('status', 'pending')->orderBy('recorded_at')->limit(5)->get()->map(fn (Sale $s) => [
            'id' => $s->id, 'label' => 'Approve '.$s->recorded_by_name.'’s '.Money::format((int) $s->amount_cents).' '.(Sales::METHODS[$s->method] ?? $s->method).' sale?',
        ])->all());
    }

    public function decide(string $id, bool $yes, string $identityId, string $name): string
    {
        $sale = Sale::query()->find($id) ?? throw new OwnerDigestRefused('That sale isn’t there any more.');
        if ($sale->status !== 'pending') {
            throw new OwnerDigestRefused('That sale has already been decided.');
        }
        try {
            $yes ? $this->sales->approve($sale, $identityId, $name) : $this->sales->decline($sale, $identityId, $name);
        } catch (SaleProblem $e) {
            throw new OwnerDigestRefused($e->getMessage());
        }

        return 'The '.Money::format((int) $sale->amount_cents).' sale is '.($yes ? 'approved' : 'declined').'.';
    }
}
