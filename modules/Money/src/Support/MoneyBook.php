<?php

declare(strict_types=1);

namespace Wurk\Money\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use Wurk\Ai\Contracts\Ai;
use Wurk\Ai\Contracts\AiActions;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Crm\Contracts\Takings;
use Wurk\Money\Models\CashUp;
use Wurk\Money\Models\Expense;
use Wurk\Money\Models\Invoice;
use Wurk\Payments\Contracts\InPersonPayments;
use Wurk\Payments\Contracts\Refunds;
use Wurk\Platform\Money\Money;
use Wurk\Sales\Contracts\SalesTotals;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * The owner's money in plain words (Sprint 23.5b): end-of-day cash-ups, expenses (typed or from a photo of the slip),
 * and "this month you took R…, spent R…, so you made about R…".
 */
final readonly class MoneyBook
{
    public const CATEGORIES = ['stock' => 'Stock', 'rent' => 'Rent', 'wages' => 'Wages', 'transport' => 'Transport', 'data' => 'Data and airtime', 'other' => 'Other'];

    public const TILL = ['cash' => 'Cash', 'card_machine' => 'Card machine', 'qr' => 'SnapScan / Zapper'];

    private const SLIPS_DISK = 'local';

    public function __construct(private TenantContext $context, private InPersonPayments $inPerson, private SalesTotals $sales, private Takings $takings,
        private Ai $ai, private AiActions $actions, private AuditLog $audit) {}

    /** @return array{cashup_reason_above_cents: int, weekly_summary: bool} */
    public function settings(): array
    {
        $r = DB::connection($this->context->connectionName())->table('money_settings')->where('tenant_id', $this->context->id())->first();

        return ['cashup_reason_above_cents' => (int) ($r->cashup_reason_above_cents ?? 2000), 'weekly_summary' => (bool) ($r->weekly_summary ?? true)];
    }

    /** @param  array{cashup_reason_above_cents?: int, weekly_summary?: bool}  $values */
    public function saveSettings(array $values): void
    {
        DB::connection($this->context->connectionName())->table('money_settings')->updateOrInsert(['tenant_id' => $this->context->id()], [...$values, 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record('money.settings', 'money_settings', $this->context->id(), $values);
    }

    /**
     * What the till should hold for a day: money taken in person (bookings, orders, invoices, sales on account paid)
     * plus in-store sales by cash and card.
     *
     * @return array{cash: int, card_machine: int, qr: int}
     */
    public function expected(string $day, ?string $branchId = null): array
    {
        [$from, $to] = $this->dayBounds($day);
        $inPerson = $this->inPerson->totals($from, $to); // the ledger's own split; branches share it unless a branch is asked for
        $sales = $this->sales->totals($from, $to, $branchId);
        $back = app(Refunds::class)->atTheCounter($from, $to); // refunds paid back at the counter today: money out (25.5)

        return ['cash' => (int) ($inPerson['cash'] ?? 0) + (int) ($sales['cash'] ?? 0) - (int) ($back['cash'] ?? 0),
            'card_machine' => (int) ($inPerson['card_machine'] ?? 0) + (int) ($sales['card'] ?? 0) - (int) ($back['card_machine'] ?? 0),
            'qr' => (int) ($inPerson['qr'] ?? 0) - (int) ($back['qr'] ?? 0)];
    }

    /**
     * Count the till and lock the day. A cash difference above the owner's amount needs a reason.
     *
     * @param  array{cash: int, card_machine: int, qr: int}  $counted  cents
     */
    public function cashUp(string $day, ?string $branchId, array $counted, ?string $reason, string $by, string $byName): CashUp
    {
        $existing = CashUp::query()->whereDate('day', $day)->where('branch_id', $branchId)->first();
        if ($existing !== null && $existing->status === 'locked') {
            throw ValidationException::withMessages(['day' => 'This day is cashed up and locked. Only the owner can reopen it.']);
        }
        $expected = $this->expected($day, $branchId);
        $counted = ['cash' => max(0, (int) $counted['cash']), 'card_machine' => max(0, (int) $counted['card_machine']), 'qr' => max(0, (int) $counted['qr'])];
        $difference = $counted['cash'] - $expected['cash'];
        $reason = trim((string) $reason) === '' ? null : mb_substr(trim((string) $reason), 0, 500);
        if (abs($difference) > $this->settings()['cashup_reason_above_cents'] && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'The cash is '.self::rand(abs($difference)).' '.($difference < 0 ? 'short' : 'over').'. Say why before locking the day.']);
        }
        $values = ['expected' => $expected, 'counted' => $counted, 'difference_cents' => $difference, 'reason' => $reason, 'status' => 'locked', 'counted_by' => $by, 'counted_by_name' => $byName];
        $cashUp = $existing === null ? CashUp::query()->create(['day' => $day, 'branch_id' => $branchId, ...$values]) : tap($existing)->update($values);
        $this->audit->record('money.cashed_up', 'cash_up', $cashUp->id, ['day' => $day, 'difference_cents' => $difference, 'reason' => $reason]);

        return $cashUp;
    }

    public function reopen(CashUp $cashUp, string $by): void
    {
        $cashUp->update(['status' => 'reopened', 'reopened_by' => $by, 'reopened_at' => now()]);
        $this->audit->record('money.cashup_reopened', 'cash_up', $cashUp->id, ['day' => $cashUp->day->toDateString()]);
    }

    /** @param  array{amount: float|int|string, category: string, spent_on: string, note?: string|null}  $data  amount: rand */
    public function addExpense(array $data, ?string $by, ?string $slipId = null): Expense
    {
        $category = array_key_exists($data['category'], self::CATEGORIES) ? $data['category'] : 'other';
        $slip = $slipId === null ? null : DB::connection($this->context->connectionName())->table('money_slips')->where('tenant_id', $this->context->id())->where('id', $slipId)->first();
        $expense = Expense::query()->create(['spent_on' => $data['spent_on'], 'amount_cents' => (int) round((float) $data['amount'] * 100), 'category' => $category,
            'note' => ($data['note'] ?? '') === '' ? null : mb_substr((string) $data['note'], 0, 200), 'slip_path' => $slip?->path, 'from_slip' => $slip !== null, 'created_by' => $by]);
        if ($slip !== null) {
            DB::connection($this->context->connectionName())->table('money_slips')->where('tenant_id', $this->context->id())->where('id', $slipId)->delete();
        }
        $this->audit->record('money.expense_added', 'expense', $expense->id, ['cents' => $expense->amount_cents, 'category' => $category, 'from_slip' => $slip !== null]);

        return $expense;
    }

    public function deleteExpense(Expense $expense): void
    {
        if ($expense->slip_path !== null) {
            Storage::disk(self::SLIPS_DISK)->delete($expense->slip_path);
        }
        $expense->delete();
        $this->audit->record('money.expense_deleted', 'expense', $expense->id, ['cents' => $expense->amount_cents]);
    }

    /**
     * A photo of the slip, read by WURK AI (decision E: AI credits per slip). Kept privately; nothing is added until
     * the owner confirms what was read.
     *
     * @return array{id: string, amount: string, spent_on: string, category: string, note: string}
     */
    public function readSlip(UploadedFile $photo, ?string $by): array
    {
        $mime = (string) $photo->getMimeType();
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || getimagesize((string) $photo->getRealPath()) === false) {
            throw ValidationException::withMessages(['slip' => 'Take a photo of the slip (JPEG, PNG or WebP).']);
        }
        $id = (string) Str::ulid();
        $path = 'money/'.$this->context->id().'/slips/'.$id.'.'.($mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg'));
        $bytes = (string) file_get_contents((string) $photo->getRealPath());
        $today = CarbonImmutable::now($this->context->current()->timezone())->toDateString();
        $read = $this->actions->perform('read_slip', $id, function () use ($bytes, $mime): array {
            $raw = $this->ai->see('slip', 'You read till slips and receipts for a small business. Reply with JSON only: {"amount": total paid in rand as a number, "date": "YYYY-MM-DD" or "", '
                .'"category": one of '.implode(', ', array_keys(self::CATEGORIES)).', "note": the shop and what was bought, under 80 characters}. Use the slip only; never guess an amount.',
                'Read this slip.', $bytes, $mime, 400, $this->context->id());

            return (array) json_decode((string) preg_replace('/^[^{]*|[^}]*$/s', '', $raw), true);
        });
        Storage::disk(self::SLIPS_DISK)->put($path, $bytes);
        $amount = is_numeric($read['amount'] ?? null) && (float) $read['amount'] > 0 && (float) $read['amount'] < 1_000_000 ? round((float) $read['amount'], 2) : null;
        $date = is_string($read['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $read['date']) === 1 && $read['date'] <= $today ? $read['date'] : $today;
        $category = is_string($read['category'] ?? null) && array_key_exists($read['category'], self::CATEGORIES) ? $read['category'] : 'other';
        $note = mb_substr(trim(strip_tags((string) ($read['note'] ?? ''))), 0, 80);
        DB::connection($this->context->connectionName())->table('money_slips')->insert(['id' => $id, 'tenant_id' => $this->context->id(), 'path' => $path,
            'read' => json_encode(['amount' => $amount, 'spent_on' => $date, 'category' => $category, 'note' => $note]), 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]);

        return ['id' => $id, 'amount' => $amount === null ? '' : number_format($amount, 2, '.', ''), 'spent_on' => $date, 'category' => $category, 'note' => $note];
    }

    /**
     * "This month you took R…, spent R…, so you made about R…" — takings after refunds (shop, bookings, in-store
     * sales) plus invoices paid, minus the expenses added.
     *
     * @return array{took: int, spent: int, made: int, by_category: array<string, int>}
     */
    public function profit(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $took = $this->takings->net($from, $to, null)
            + (int) Invoice::query()->where('status', 'paid')->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->sum('total_cents')
            - app(Refunds::class)->totalFor('invoice', $from, $to); // invoice refunds, in the month they're made (25.5)
        $tz = $this->context->current()->timezone();
        $byCategory = Expense::query()->where('spent_on', '>=', CarbonImmutable::instance($from)->setTimezone($tz)->toDateString())
            ->where('spent_on', '<', CarbonImmutable::instance($to)->setTimezone($tz)->toDateString())
            ->get(['category', 'amount_cents'])->groupBy('category')->map(fn ($g) => (int) $g->sum('amount_cents'))->all();
        $spent = array_sum($byCategory);

        return ['took' => $took, 'spent' => $spent, 'made' => $took - $spent, 'by_category' => $byCategory];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} a business day in UTC */
    public function dayBounds(string $day): array
    {
        $start = CarbonImmutable::parse($day, $this->context->current()->timezone())->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    public static function rand(int $cents): string
    {
        return Money::format((int) $cents);
    }

    /** A slip's photo, for the person who added the expense (never public). */
    public function slip(Expense $expense): ?string
    {
        try {
            return $expense->slip_path === null ? null : Storage::disk(self::SLIPS_DISK)->get($expense->slip_path);
        } catch (Throwable) {
            return null;
        }
    }
}
