<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Tenancy\Actions\CreateTenant;
use Wurk\Tenancy\Events\OwnerInvitationRequested;
use Wurk\Tenancy\Events\TenantStatusChanged;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Tenancy\Models\TenantDomain;
use Wurk\Tenancy\Resolution\TenantResolver;
use Wurk\Tenancy\Support\HandleRules;

/** WURK staff: businesses list, detail, manual creation with owner invite, suspend / reactivate. */
final class BusinessesController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Tenant::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:active,suspended,provisioning'],
            'shard' => ['nullable', 'string', 'max:30'],
        ]);

        $page = Tenant::query()->with(['shard', 'domains'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('handle', 'like', "%{$s}%")))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['shard'] ?? null, fn ($q, $s) => $q->whereHas('shard', fn ($q) => $q->where('name', $s)))
            ->orderByDesc('created_at')->paginate(25)->withQueryString();

        return Inertia::render('Tenancy::Admin/Businesses', [
            'filters' => array_filter($filters),
            'shards' => Shard::query()->orderBy('name')->pluck('name')->all(),
            'canCreate' => Gate::allows('create', Tenant::class),
            'businesses' => [
                'data' => collect($page->items())->map(fn (Tenant $t) => self::summary($t))->all(),
                'total' => $page->total(),
                'next' => $page->nextPageUrl(),
                'prev' => $page->previousPageUrl(),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Tenant::class);

        return Inertia::render('Tenancy::Admin/CreateBusiness', ['rootDomain' => config('wurk.domains.root')]);
    }

    public function checkHandle(Request $request): JsonResponse
    {
        Gate::authorize('create', Tenant::class);
        $handle = strtolower((string) $request->query('handle', ''));
        $problem = HandleRules::problem($handle) ?? (Tenant::query()->where('handle', $handle)->exists() ? 'That name is already taken.' : null);

        return new JsonResponse(['available' => $problem === null, 'problem' => $problem]);
    }

    public function store(Request $request, CreateTenant $create): RedirectResponse
    {
        Gate::authorize('create', Tenant::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'handle' => ['required', 'string', 'max:40'],
            'country' => ['required', 'exists:countries,code,enabled,1'],
            'owner_first_name' => ['required', 'string', 'max:80'],
            'owner_last_name' => ['nullable', 'string', 'max:80'],
            'owner_contact' => ['required', 'string', 'max:255'],
        ]);

        $tenant = $create($data['name'], $data['handle'], $data['country']);
        event(new OwnerInvitationRequested($tenant->id, $tenant->name, $data['owner_contact'], $data['owner_first_name'], $data['owner_last_name'] ?? '', (string) $request->user()?->getAuthIdentifier()));

        return redirect()->route('admin.businesses.show', $tenant)->with('status', "{$tenant->name} is ready. We sent {$data['owner_contact']} an invitation.");
    }

    public function show(Tenant $tenant): Response
    {
        Gate::authorize('view', $tenant);
        $tenant->load(['shard', 'domains']);

        return Inertia::render('Tenancy::Admin/Business', [
            'business' => self::summary($tenant) + [
                'id' => $tenant->id,
                'country' => $tenant->country,
                'timezone' => $tenant->timezone,
                'domains' => $tenant->domains->map(fn ($d) => ['host' => $d->host, 'type' => $d->type, 'primary' => $d->is_primary, 'verified' => $d->verified_at !== null])->all(),
            ],
            'canManage' => Gate::allows('update', $tenant),
            'auditUrl' => route('admin.audit', ['subject' => $tenant->id]),
        ]);
    }

    public function suspend(Request $request, Tenant $tenant): RedirectResponse
    {
        return $this->changeStatus($request, $tenant, Tenant::STATUS_SUSPENDED, 'suspended');
    }

    public function reactivate(Request $request, Tenant $tenant): RedirectResponse
    {
        return $this->changeStatus($request, $tenant, Tenant::STATUS_ACTIVE, 'reactivated');
    }

    private function changeStatus(Request $request, Tenant $tenant, string $status, string $verb): RedirectResponse
    {
        Gate::authorize('suspend', $tenant);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $from = $tenant->status;

        $tenant->forceFill(['status' => $status])->saveQuietly();
        app(TenantResolver::class)->forgetHandle($tenant->handle);
        event(new TenantStatusChanged($tenant->id, $tenant->handle, $from, $status, $data['reason']));

        return back()->with('status', "{$tenant->name} was {$verb}.");
    }

    /** @return array<string, mixed> */
    private static function summary(Tenant $t): array
    {
        $primary = $t->domains->firstWhere('is_primary', true);

        return [
            'id' => $t->id,
            'name' => $t->name,
            'handle' => $t->handle,
            'status' => $t->status,
            'shard' => $t->shard?->name,
            'domain' => $primary instanceof TenantDomain ? $primary->host : null,
            'created' => $t->created_at?->toIso8601String(),
        ];
    }
}
