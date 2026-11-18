<?php

declare(strict_types=1);

namespace Wurk\Money\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Ai\Contracts\AiActionRefused;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Money\Models\CashUp;
use Wurk\Money\Models\Expense;
use Wurk\Money\Support\MoneyBook;
use Wurk\Money\Support\WeeklySummary;
use Wurk\Tenancy\Contracts\TenantContext;

/** Owners: Money → Cash-up, and Money → Expenses and profit (23.5b). */
final class MoneyBookController
{
    public function __construct(private MoneyBook $book, private TenantContext $context, private TeamDirectory $team) {}

    public function cashUp(Request $request): Response
    {
        $tz = $this->context->current()->timezone();
        $day = is_string($d = $request->query('day')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && $d <= CarbonImmutable::now($tz)->toDateString() ? $d : CarbonImmutable::now($tz)->toDateString();
        $existing = CashUp::query()->whereDate('day', $day)->whereNull('branch_id')->first();

        return Inertia::render('Money::CashUp', [
            'day' => $day, 'expected' => $this->book->expected($day), 'till' => MoneyBook::TILL,
            'cashUp' => $existing === null ? null : self::row($existing),
            'recent' => CashUp::query()->orderByDesc('day')->limit(14)->get()->map(fn (CashUp $c) => self::row($c))->all(),
            'reasonAbove' => $this->book->settings()['cashup_reason_above_cents'],
            'isOwner' => $this->isOwner($request),
        ]);
    }

    public function count(Request $request): RedirectResponse
    {
        $data = $request->validate(['day' => ['required', 'date_format:Y-m-d'], 'cash' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'card_machine' => ['nullable', 'numeric', 'min:0', 'max:10000000'], 'qr' => ['nullable', 'numeric', 'min:0', 'max:10000000'], 'reason' => ['nullable', 'string', 'max:500']]);
        $cents = fn ($v) => (int) round((float) ($v ?? 0) * 100);
        $me = (string) $request->user()?->getAuthIdentifier();
        $c = $this->book->cashUp($data['day'], null, ['cash' => $cents($data['cash']), 'card_machine' => $cents($data['card_machine'] ?? 0), 'qr' => $cents($data['qr'] ?? 0)],
            $data['reason'] ?? null, $me, $this->name($me));

        return back()->with('status', 'Cashed up and locked'.($c->difference_cents === 0 ? ': the cash matches.' : ': '.MoneyBook::rand(abs($c->difference_cents)).' '.($c->difference_cents < 0 ? 'short.' : 'over.')));
    }

    public function reopen(Request $request, string $cashUp): RedirectResponse
    {
        abort_unless($this->isOwner($request), 403);
        $this->book->reopen(CashUp::query()->findOrFail($cashUp), (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Reopened. Count again to lock it.');
    }

    public function expenses(Request $request, PeopleDirectory $people): Response
    {
        $tz = $this->context->current()->timezone();
        $month = CarbonImmutable::now($tz)->startOfMonth();
        $me = (string) $request->user()?->getAuthIdentifier();

        return Inertia::render('Money::Expenses', [
            'profit' => $this->book->profit($month->utc(), $month->addMonth()->utc()), 'month' => $month->format('F Y'),
            'expenses' => Expense::query()->orderByDesc('spent_on')->orderByDesc('created_at')->limit(100)->get()->map(fn (Expense $e) => ['id' => $e->id,
                'spent_on' => $e->spent_on->toDateString(), 'amount_cents' => $e->amount_cents, 'category' => $e->category, 'note' => $e->note, 'slip' => $e->slip_path !== null])->all(),
            'categories' => MoneyBook::CATEGORIES, 'today' => CarbonImmutable::now($tz)->toDateString(),
            'slip' => $request->session()->get('slip'), // what WURK AI read, for the owner to confirm
            'weekly' => ['on' => $this->book->settings()['weekly_summary'], 'phone' => $people->verifiedPhone($me) !== null],
            'reasonAbove' => $this->book->settings()['cashup_reason_above_cents'],
        ]);
    }

    public function addExpense(Request $request): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'], 'category' => ['required', 'string', 'max:20'],
            'spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'note' => ['nullable', 'string', 'max:200'], 'slip_id' => ['nullable', 'string', 'size:26']]);
        $e = $this->book->addExpense($data, (string) $request->user()?->getAuthIdentifier(), $data['slip_id'] ?? null);

        return back()->with('status', MoneyBook::rand($e->amount_cents).' added to '.strtolower(MoneyBook::CATEGORIES[$e->category]).'.');
    }

    public function readSlip(Request $request): RedirectResponse
    {
        $request->validate(['slip' => ['required', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp']]);
        try {
            $read = $this->book->readSlip($request->file('slip'), (string) $request->user()?->getAuthIdentifier());
        } catch (AiActionRefused $e) {
            throw ValidationException::withMessages(['slip' => $e->getMessage().' You can still type the expense in.']);
        } catch (AiUnavailable) {
            throw ValidationException::withMessages(['slip' => 'WURK AI couldn’t read it right now. Type the expense in, or try again in a minute.']);
        }

        return back()->with('slip', $read)->with('status', 'Check what WURK read, then add it.');
    }

    public function deleteExpense(string $expense): RedirectResponse
    {
        $this->book->deleteExpense(Expense::query()->findOrFail($expense));

        return back()->with('status', 'Expense removed.');
    }

    public function slip(string $expense): HttpResponse
    {
        $bytes = $this->book->slip(Expense::query()->findOrFail($expense)) ?? abort(404);

        return new HttpResponse($bytes, 200, ['Content-Type' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/jpeg', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function settings(Request $request, PeopleDirectory $people): RedirectResponse
    {
        $data = $request->validate(['cashup_reason_above' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'weekly_summary' => ['nullable', 'boolean']]);
        $values = [];
        if (array_key_exists('cashup_reason_above', $data) && $data['cashup_reason_above'] !== null) {
            $values['cashup_reason_above_cents'] = (int) round((float) $data['cashup_reason_above'] * 100);
        }
        if (array_key_exists('weekly_summary', $data) && $data['weekly_summary'] !== null) {
            $values['weekly_summary'] = (bool) $data['weekly_summary'];
            if ($values['weekly_summary'] && ($phone = $people->verifiedPhone((string) $request->user()?->getAuthIdentifier())) !== null) {
                WeeklySummary::resume($phone); // switching it on undoes an earlier STOP
            }
        }
        $this->book->saveSettings($values);

        return back()->with('status', 'Saved.');
    }

    /** @return array<string, mixed> */
    private static function row(CashUp $c): array
    {
        return ['id' => $c->id, 'day' => $c->day->toDateString(), 'expected' => $c->expected, 'counted' => $c->counted, 'difference_cents' => $c->difference_cents,
            'reason' => $c->reason, 'status' => $c->status, 'by' => $c->counted_by_name];
    }

    private function isOwner(Request $request): bool
    {
        $me = (string) $request->user()?->getAuthIdentifier();

        return collect($this->team->roster())->contains(fn ($m) => $m['identity_id'] === $me && $m['role'] === 'owner' && $m['active']);
    }

    private function name(string $me): string
    {
        return (string) (collect($this->team->roster())->firstWhere('identity_id', $me)['name'] ?? 'Team');
    }
}
