<?php

declare(strict_types=1);

namespace Wurk\Shop\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\CatalogChanged;

/** Owners: Shop → Settings — collection, delivery, and paying by EFT or on collection. */
final class ShopSettingsController
{
    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    public function show(): Response
    {
        $s = ShopSettings::query()->find($this->context->id());

        return Inertia::render('Shop::Settings', ['settings' => [
            'collection_enabled' => $s === null || $s->collection_enabled, 'collection_address' => (string) $s?->collection_address,
            'delivery_enabled' => (bool) $s?->delivery_enabled, 'delivery_fee_cents' => (int) $s?->delivery_fee_cents, 'delivery_note' => (string) $s?->delivery_note,
            'offline_hold_hours' => $s === null ? 72 : $s->offline_hold_hours,
            'ships_from_branch_id' => ($s === null ? null : $s->ships_from_branch_id) ?? app(BranchDirectory::class)->main(),
        ], 'branches' => array_map(fn (array $b) => ['id' => $b['id'], 'name' => $b['name']], app(BranchDirectory::class)->open())]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'collection_enabled' => ['boolean'], 'collection_address' => ['nullable', 'string', 'max:300'],
            'delivery_enabled' => ['boolean'], 'delivery_fee_cents' => ['required', 'integer', 'min:0', 'max:10000000'], 'delivery_note' => ['nullable', 'string', 'max:300'],
            'offline_hold_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'ships_from_branch_id' => ['nullable', 'string', Rule::in(array_column(app(BranchDirectory::class)->open(), 'id'))],
        ]);
        if (! ($data['collection_enabled'] ?? false) && ! ($data['delivery_enabled'] ?? false)) {
            throw ValidationException::withMessages(['collection_enabled' => 'Offer collection, delivery, or both.']);
        }
        if (($data['collection_enabled'] ?? false) && trim((string) ($data['collection_address'] ?? '')) === '') {
            throw ValidationException::withMessages(['collection_address' => 'Add the address customers collect from.']);
        }
        ShopSettings::query()->updateOrCreate(['tenant_id' => $this->context->id()], [
            'collection_enabled' => (bool) ($data['collection_enabled'] ?? false), 'collection_address' => $data['collection_address'] ?: null,
            'delivery_enabled' => (bool) ($data['delivery_enabled'] ?? false), 'delivery_fee_cents' => $data['delivery_fee_cents'], 'delivery_note' => $data['delivery_note'] ?: null,
            'offline_hold_hours' => $data['offline_hold_hours'], 'ships_from_branch_id' => $data['ships_from_branch_id'] ?? null,
        ]);
        $this->audit->record('shop.settings_saved', 'shop_settings', $this->context->id(), []);
        event(new CatalogChanged($this->context->id())); // "Add to cart" and the cart page follow the settings

        return back()->with('status', 'Shop settings saved.');
    }
}
