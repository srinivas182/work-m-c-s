<?php

declare(strict_types=1);

namespace Wurk\Shop\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Tenancy\Contracts\TenantContext;

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
            'offline_enabled' => (bool) $s?->offline_enabled, 'offline_instructions' => (string) $s?->offline_instructions, 'offline_hold_hours' => $s === null ? 72 : $s->offline_hold_hours,
        ]]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'collection_enabled' => ['boolean'], 'collection_address' => ['nullable', 'string', 'max:300'],
            'delivery_enabled' => ['boolean'], 'delivery_fee_cents' => ['required', 'integer', 'min:0', 'max:10000000'], 'delivery_note' => ['nullable', 'string', 'max:300'],
            'offline_enabled' => ['boolean'], 'offline_instructions' => ['nullable', 'string', 'max:2000'], 'offline_hold_hours' => ['required', 'integer', 'min:1', 'max:720'],
        ]);
        if (! ($data['collection_enabled'] ?? false) && ! ($data['delivery_enabled'] ?? false)) {
            throw ValidationException::withMessages(['collection_enabled' => 'Offer collection, delivery, or both.']);
        }
        if (($data['collection_enabled'] ?? false) && trim((string) ($data['collection_address'] ?? '')) === '') {
            throw ValidationException::withMessages(['collection_address' => 'Add the address customers collect from.']);
        }
        if (($data['offline_enabled'] ?? false) && trim((string) ($data['offline_instructions'] ?? '')) === '') {
            throw ValidationException::withMessages(['offline_instructions' => 'Tell customers how to pay — e.g. your bank details, or “pay when you collect”.']);
        }
        ShopSettings::query()->updateOrCreate(['tenant_id' => $this->context->id()], [
            'collection_enabled' => (bool) ($data['collection_enabled'] ?? false), 'collection_address' => $data['collection_address'] ?: null,
            'delivery_enabled' => (bool) ($data['delivery_enabled'] ?? false), 'delivery_fee_cents' => $data['delivery_fee_cents'], 'delivery_note' => $data['delivery_note'] ?: null,
            'offline_enabled' => (bool) ($data['offline_enabled'] ?? false), 'offline_instructions' => $data['offline_instructions'] ?: null, 'offline_hold_hours' => $data['offline_hold_hours'],
        ]);
        $this->audit->record('shop.settings_saved', 'shop_settings', $this->context->id(), []);

        return back()->with('status', 'Shop settings saved.');
    }
}
