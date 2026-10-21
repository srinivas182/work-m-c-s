<?php

declare(strict_types=1);

namespace Wurk\Plans\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Models\Package;
use Wurk\Plans\Models\PackageVersion;
use Wurk\Plans\Models\TenantSubscription;
use Wurk\Plans\Support\ModuleCatalogue;

final class PackagesController
{
    public function __construct(private readonly PackageCatalog $catalog, private readonly ModuleCatalogue $modules) {}

    public function index(): Response
    {
        Gate::authorize('packages.view');
        $subscribers = TenantSubscription::query()->selectRaw('package_id, count(*) as total')->groupBy('package_id')->pluck('total', 'package_id');

        return Inertia::render('Plans::Admin/Packages', [
            'canManage' => Gate::allows('packages.manage'),
            'modules' => $this->modules->all(),
            'packages' => Package::query()->with('currentVersion')->orderBy('display_order')->orderBy('name')->get()->map(fn (Package $p) => [
                ...self::present($p),
                'subscribers' => (int) ($subscribers[$p->id] ?? 0),
            ])->all(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('packages.manage');

        return Inertia::render('Plans::Admin/PackageForm', ['package' => null, 'modules' => $this->modules->all(), 'versions' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('packages.manage');
        $data = $request->validate([...$this->rules(), 'key' => ['required', 'regex:/^[a-z0-9-]{2,60}$/', 'unique:packages,key'], 'type' => ['required', Rule::in([Package::SELF_MANAGED, Package::MANAGED])]]);
        $package = $this->catalog->create(self::money($data), (string) $request->user()?->getAuthIdentifier());

        return redirect()->route('admin.packages.edit', $package)->with('status', "Package “{$package->name}” created.");
    }

    public function edit(Package $package): Response
    {
        Gate::authorize('packages.view');
        $package->load('currentVersion');

        return Inertia::render('Plans::Admin/PackageForm', [
            'package' => self::present($package),
            'modules' => $this->modules->all(),
            'canManage' => Gate::allows('packages.manage'),
            'versions' => PackageVersion::query()->where('package_id', $package->id)->orderByDesc('version')->get()->map(fn (PackageVersion $v) => [
                'version' => $v->version,
                'monthly' => $v->monthly_price_cents,
                'yearly' => $v->yearly_price_cents,
                'created' => $v->created_at?->toIso8601String(),
                'subscribers' => TenantSubscription::query()->where('package_version_id', $v->id)->count(),
            ])->all(),
        ]);
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        Gate::authorize('packages.manage');
        $data = $request->validate($this->rules());
        $before = $package->current_version_id;
        $package = $this->catalog->update($package, self::money($data), (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', $package->current_version_id === $before
            ? 'Package saved.'
            : 'New version saved. Existing businesses keep their current terms; new subscriptions get the new ones.');
    }

    public function archive(Package $package): RedirectResponse
    {
        Gate::authorize('packages.manage');
        $this->catalog->archive($package, $package->status === 'active');

        return back()->with('status', $package->status === 'active' ? 'Package archived — hidden from sale.' : 'Package available for sale again.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'visibility' => ['required', Rule::in(['public', 'private'])],
            'display_order' => ['required', 'integer', 'between:0,1000'],
            'monthly_price' => ['required', 'numeric', 'between:0,1000000'],
            'yearly_price' => ['required', 'numeric', 'between:0,10000000'],
            'trial_days' => ['required', 'integer', 'between:0,90'],
            'storage_mb' => ['required', 'integer', 'between:50,1048576'],
            'staff_limit' => ['required', 'integer', 'between:1,10000'],
            'multi_branch' => ['boolean'],
            'max_branches' => ['required', 'integer', 'between:1,1000'],
            'custom_domain' => ['boolean'],
            'monthly_credits' => ['required', 'integer', 'between:0,1000000'],
            'referral_max_levels' => ['required', 'integer', 'between:0,7'],
            'referral_max_commission' => ['required', 'numeric', 'between:0,80'],
            'managed_fee' => ['nullable', 'numeric', 'between:0,1000000'],
            'modules' => ['array'],
            'modules.*' => ['string', Rule::in($this->modules->keys())],
        ];
    }

    /**
     * Rand amounts and percentages from the form → integer cents and basis points.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function money(array $data): array
    {
        return [
            ...$data,
            // Empty form fields arrive as null (ConvertEmptyStringsToNull); the column is NOT NULL.
            'description' => (string) ($data['description'] ?? ''),
            'monthly_price_cents' => (int) round((float) $data['monthly_price'] * 100),
            'yearly_price_cents' => (int) round((float) $data['yearly_price'] * 100),
            'managed_fee_cents' => isset($data['managed_fee']) && $data['managed_fee'] !== '' ? (int) round((float) $data['managed_fee'] * 100) : null,
            'referral_max_commission_bp' => (int) round((float) $data['referral_max_commission'] * 100),
            'modules' => $data['modules'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private static function present(Package $p): array
    {
        $v = $p->currentVersion;

        return [
            'id' => $p->id, 'key' => $p->key, 'name' => $p->name, 'description' => $p->description, 'type' => $p->type,
            'visibility' => $p->visibility, 'display_order' => $p->display_order, 'status' => $p->status,
            'version' => $v?->version,
            'terms' => $v === null ? null : [
                'monthly_price' => $v->monthly_price_cents / 100, 'yearly_price' => $v->yearly_price_cents / 100,
                'trial_days' => $v->trial_days, 'storage_mb' => $v->storage_mb, 'staff_limit' => $v->staff_limit,
                'multi_branch' => $v->multi_branch, 'max_branches' => $v->max_branches, 'custom_domain' => $v->custom_domain,
                'monthly_credits' => $v->monthly_credits, 'referral_max_levels' => $v->referral_max_levels,
                'referral_max_commission' => $v->referral_max_commission_bp / 100,
                'managed_fee' => $v->managed_fee_cents === null ? null : $v->managed_fee_cents / 100, 'modules' => $v->modules,
            ],
        ];
    }
}
