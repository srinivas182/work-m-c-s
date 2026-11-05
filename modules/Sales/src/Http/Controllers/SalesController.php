<?php

declare(strict_types=1);

namespace Wurk\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Contracts\BranchRestriction;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Sales\Models\Sale;
use Wurk\Sales\Support\SaleProblem;
use Wurk\Sales\Support\Sales;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

/** Sales → Record sale: record an in-store sale; approve, decline or refund; Settings → Sales: the approval threshold. */
final class SalesController
{
    public function __construct(private Sales $sales, private TenantContext $context, private BranchDirectory $branches, private ContactBook $contacts) {}

    public function index(Request $request): Response
    {
        $limit = $this->limit($request);
        $open = array_values(array_filter($this->branches->open(), fn (array $b) => $limit === null || in_array($b['id'], $limit, true)));
        $rows = Sale::query()->when($limit !== null, fn ($q) => $q->whereIn('branch_id', $limit))
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere('recorded_at', '>=', now()->subDays(1)))->orderByDesc('recorded_at')->limit(100)->get();
        $names = $this->contacts->names(array_values(array_filter($rows->pluck('contact_id')->all())));
        $tz = $this->context->current()->timezone();

        return Inertia::render('Sales::Record', [
            'branches' => $this->branches->several() ? array_map(fn (array $b) => ['id' => $b['id'], 'name' => $b['name']], $open) : [],
            'methods' => Sales::METHODS,
            'threshold' => $this->sales->threshold(),
            'canApprove' => Gate::allows('sales.approve'),
            'canManage' => Gate::allows('sales.manage'),
            'sales' => $rows->map(fn (Sale $s) => ['id' => $s->id, 'customer' => $names[$s->contact_id ?? ''] ?? 'Customer', 'amount_cents' => $s->amount_cents,
                'method' => Sales::METHODS[$s->method] ?? $s->method, 'status' => $s->status, 'by' => $s->recorded_by_name, 'refunded_cents' => $s->refunded_cents,
                'at' => $s->recorded_at->setTimezone($tz)->format('j M, H:i'), 'note' => $s->note])->all(),
        ]);
    }

    /** Who staff are about to credit: a customer found by phone or email (exact, like everywhere in WURK). */
    public function find(Request $request): JsonResponse
    {
        $c = $this->contacts->find($request->string('phone')->toString() ?: null, $request->string('email')->toString() ?: null);

        return new JsonResponse(['customer' => $c === null ? null : ['name' => $c['name'], 'email' => $c['email'] !== null]]);
    }

    public function store(Request $request): RedirectResponse
    {
        $limit = $this->limit($request);
        $open = array_column($this->branches->open(), 'id');
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[+0-9 ()-]{6,20}$/', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:120', 'required_without:phone'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'method' => ['required', Rule::in(array_keys(Sales::METHODS))],
            'note' => ['nullable', 'string', 'max:200'],
            'branch_id' => ['nullable', Rule::in($limit ?? $open)],
        ], ['phone.required_without' => 'Give the customer’s phone or email, so the sale is theirs.', 'email.required_without' => 'Give the customer’s phone or email, so the sale is theirs.',
            'amount.min' => 'Enter the amount paid.']);
        $branch = $d['branch_id'] ?? ($limit[0] ?? ($this->branches->several() ? null : $this->branches->main()));
        if ($this->branches->several() && $branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'Choose the branch the sale was made at.']);
        }
        $user = $request->user();
        $sale = $this->sales->record(['name' => $d['name'], 'phone' => $d['phone'] ?? null, 'email' => $d['email'] ?? null, 'amount_cents' => (int) round((float) $d['amount'] * 100),
            'method' => $d['method'], 'note' => $d['note'] ?? null, 'branch_id' => $branch],
            (string) $user?->getAuthIdentifier(), $this->who($request), mb_substr((string) $request->userAgent(), 0, 120), Gate::allows('sales.approve'));

        return back()->with('status', $sale->status === 'pending' ? 'Sale saved — it needs a manager’s approval (over R'.number_format($this->sales->threshold() / 100, 2).').' : 'Sale recorded.');
    }

    public function approve(Request $request, string $sale): RedirectResponse
    {
        return $this->act(fn () => $this->sales->approve($this->sale($request, $sale), (string) $request->user()?->getAuthIdentifier(), $this->who($request)), 'Sale approved.');
    }

    public function decline(Request $request, string $sale): RedirectResponse
    {
        return $this->act(fn () => $this->sales->decline($this->sale($request, $sale), (string) $request->user()?->getAuthIdentifier(), $this->who($request)), 'Sale declined.');
    }

    public function refund(Request $request, string $sale): RedirectResponse
    {
        $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'min:0.01']])['amount'];

        return $this->act(fn () => $this->sales->refund($this->sale($request, $sale), (int) round($amount * 100), $this->who($request)), 'Refund recorded.');
    }

    public function threshold(Request $request): RedirectResponse
    {
        $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'min:0', 'max:1000000']], ['amount.min' => 'Use 0 for no approvals.'])['amount'];
        $this->sales->setThreshold((int) round($amount * 100));

        return back()->with('status', $amount > 0 ? 'Sales over R'.number_format($amount, 2).' now need approval.' : 'Sales no longer need approval.');
    }

    private function act(callable $fn, string $done): RedirectResponse
    {
        try {
            $fn();
        } catch (SaleProblem $e) {
            throw ValidationException::withMessages(['sale' => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }

    /** A sale this person may act on (branch managers: their branches only). */
    private function sale(Request $request, string $id): Sale
    {
        $limit = $this->limit($request);

        return Sale::query()->whereKey($id)->when($limit !== null, fn ($q) => $q->whereIn('branch_id', $limit))->firstOrFail();
    }

    /** @return list<string>|null */
    private function limit(Request $request): ?array
    {
        return app(BranchRestriction::class)->branchRestriction((string) $request->user()?->getAuthIdentifier());
    }

    private function who(Request $request): string
    {
        return trim((string) data_get($request->user(), 'first_name').' '.(string) data_get($request->user(), 'last_name')) ?: 'Team';
    }
}
