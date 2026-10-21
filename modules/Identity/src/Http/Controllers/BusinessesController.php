<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\Membership;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** After sign-in on app.wurk.co.za: go straight to the only business, or choose. */
final class BusinessesController
{
    public function __invoke(Request $request, TenantDirectory $directory): Response|RedirectResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof Identity, 401);

        /** @var list<string> $ids */
        $ids = array_values(array_map('strval', Membership::query()->where('identity_id', $identity->id)
            ->where('kind', Membership::KIND_STAFF)->where('status', 'active')->pluck('tenant_id')->all()));
        $businesses = $directory->activeAmong($ids);

        if (count($businesses) === 1 && ! $request->boolean('choose')) {
            return redirect()->route('tenant.home', ['tenant' => $businesses[0]->handle()]);
        }

        return Inertia::render('Identity::Businesses', [
            'businesses' => array_map(fn (CurrentTenant $t) => [
                'name' => $t->displayName(),
                'handle' => $t->handle(),
                'url' => route('tenant.home', ['tenant' => $t->handle()]),
            ], $businesses),
        ]);
    }
}
