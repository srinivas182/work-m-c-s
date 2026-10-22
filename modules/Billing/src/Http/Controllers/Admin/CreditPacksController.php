<?php

declare(strict_types=1);

namespace Wurk\Billing\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Billing\Models\CreditPack;

/** WURK staff: the credit packs businesses can buy. */
final class CreditPacksController
{
    public function index(): Response
    {
        Gate::authorize('credits.manage');

        return Inertia::render('Billing::Admin/CreditPacks', [
            'packs' => CreditPack::query()->orderBy('display_order')->orderBy('credits')->get()
                ->map(fn (CreditPack $p) => ['id' => $p->id, 'name' => $p->name, 'credits' => $p->credits, 'price' => $p->price_cents / 100, 'active' => $p->active])->all(),
        ]);
    }

    public function store(Request $request, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('credits.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'credits' => ['required', 'integer', 'between:1,1000000'], 'price' => ['required', 'numeric', 'between:1,1000000']]);
        $pack = CreditPack::query()->create(['name' => $data['name'], 'credits' => (int) $data['credits'], 'price_cents' => (int) round((float) $data['price'] * 100)]);
        $audit->record('credits.pack_created', 'credit_pack', $pack->id, $data, ['platform' => true]);

        return back()->with('status', 'Credit pack added.');
    }

    public function toggle(string $pack, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('credits.manage');
        $model = CreditPack::query()->findOrFail($pack);
        $model->update(['active' => ! $model->active]);
        $audit->record($model->active ? 'credits.pack_enabled' : 'credits.pack_disabled', 'credit_pack', $model->id, [], ['platform' => true]);

        return back()->with('status', $model->active ? 'Pack on sale.' : 'Pack withdrawn from sale.');
    }
}
