<?php

declare(strict_types=1);

namespace Wurk\Team\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

/** Owners: Settings → Branches — add, edit, close and reopen, within the package's limit. */
final class BranchesController
{
    public function __construct(private TenantContext $context, private BranchDirectory $branches, private Entitlements $entitlements, private AuditLog $audit) {}

    public function index(): Response
    {
        $limit = $this->limit();

        return Inertia::render('Team::Branches', [
            'branches' => $this->branches->all(), 'limit' => $limit, 'open' => count($this->branches->open()),
            'canManage' => Gate::allows('branches.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('branches.manage');
        $data = $this->validated($request);
        if (count($this->branches->open()) >= $this->limit()) {
            throw ValidationException::withMessages(['name' => $this->limit() === 1
                ? 'Your package has one branch. Upgrade to add more.'
                : "Your package allows {$this->limit()} branches. Close one, or upgrade to add more."]);
        }
        $id = $this->branches->add($data['name'], $data['address'], $data['phone']);
        $this->audit->record('branches.added', 'branch', $id, ['name' => $data['name']]);

        return back()->with('status', 'Branch added.');
    }

    public function update(Request $request, string $branch): RedirectResponse
    {
        Gate::authorize('branches.manage');
        $data = $this->validated($request);
        $this->branches->update($branch, $data['name'], $data['address'], $data['phone']);
        $this->audit->record('branches.updated', 'branch', $branch, []);

        return back()->with('status', 'Branch saved.');
    }

    public function close(Request $request, string $branch): RedirectResponse
    {
        Gate::authorize('branches.manage');
        $closed = (bool) $request->validate(['closed' => ['required', 'boolean']])['closed'];
        if (! $closed && count($this->branches->open()) >= $this->limit()) {
            throw ValidationException::withMessages(['closed' => "Your package allows {$this->limit()} open branches."]);
        }
        try {
            $this->branches->setClosed($branch, $closed);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['closed' => $e->getMessage()]);
        }
        $this->audit->record($closed ? 'branches.closed' : 'branches.reopened', 'branch', $branch, []);

        return back()->with('status', $closed ? 'Branch closed. Its history is kept.' : 'Branch reopened.');
    }

    private function limit(): int
    {
        $e = $this->entitlements->limits($this->context->id());

        return $e !== null && $e['multi_branch'] ? max(1, $e['max_branches']) : 1;
    }

    /** @return array{name: string, address: string|null, phone: string|null} */
    private function validated(Request $request): array
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'address' => ['nullable', 'string', 'max:300'], 'phone' => ['nullable', 'string', 'max:40']]);

        return ['name' => trim($d['name']), 'address' => ($d['address'] ?? null) ?: null, 'phone' => ($d['phone'] ?? null) ?: null];
    }
}
