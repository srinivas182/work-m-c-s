<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Support;

use Illuminate\Support\Str;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Models\Branch;

final class Branches implements BranchDirectory
{
    public function open(): array
    {
        return array_values(Branch::query()->whereNull('closed_at')->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'address' => $b->address, 'phone' => $b->phone, 'main' => $b->is_default])->all());
    }

    public function several(): bool
    {
        return Branch::query()->whereNull('closed_at')->count() > 1;
    }

    public function main(): string
    {
        return (string) Branch::query()->where('is_default', true)->value('id');
    }

    public function all(): array
    {
        return array_values(Branch::query()->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'address' => $b->address, 'phone' => $b->phone, 'main' => $b->is_default, 'closed' => $b->closed_at !== null])->all());
    }

    public function add(string $name, ?string $address, ?string $phone): string
    {
        $main = Branch::query()->where('is_default', true)->first();
        $base = Str::slug($name) ?: 'branch';
        $code = $base;
        for ($n = 2; Branch::query()->where('code', $code)->exists(); $n++) {
            $code = "{$base}-{$n}";
        }

        return Branch::query()->create(['name' => $name, 'code' => $code, 'timezone' => $main->timezone ?? 'Africa/Johannesburg', 'is_default' => false, 'address' => $address, 'phone' => $phone])->id;
    }

    public function update(string $id, string $name, ?string $address, ?string $phone): void
    {
        Branch::query()->whereKey($id)->firstOrFail()->update(['name' => $name, 'address' => $address, 'phone' => $phone]);
    }

    public function setClosed(string $id, bool $closed): void
    {
        $branch = Branch::query()->whereKey($id)->firstOrFail();
        if ($closed && $branch->is_default) {
            throw new \RuntimeException('The main branch can’t be closed.');
        }
        $branch->update(['closed_at' => $closed ? now() : null]);
    }
}
