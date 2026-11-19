<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Payments\Contracts\Refunds;
use Wurk\Platform\Money\Money;
use Wurk\Tenancy\Contracts\TenantContext;

final readonly class RefundLedger implements Refunds
{
    public function __construct(private TenantContext $context, private Messenger $messenger, private AuditLog $audit) {}

    public function record(string $kind, string $sourceId, int $cents, string $method, string $reason, ?string $note, ?string $by, string $byName, ?string $branchId,
        array $customer = []): string
    {
        if (! isset(self::METHODS[$method]) || $cents < 1 || trim($reason) === '') {
            throw new InvalidArgumentException('A refund needs an amount, a way it was paid back and a reason.');
        }
        $id = (string) Str::ulid();
        $note = ($note = trim(strip_tags((string) $note))) === '' ? null : mb_substr($note, 0, 500);
        DB::connection($this->context->connectionName())->table('refunds')->insert([
            'id' => $id, 'tenant_id' => $this->context->id(), 'kind' => $kind, 'source_id' => $sourceId, 'amount_cents' => $cents, 'method' => $method,
            'reason' => mb_substr(trim($reason), 0, 200), 'note' => $note, 'by' => $by, 'by_name' => mb_substr($byName, 0, 120), 'branch_id' => $branchId,
            'refunded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('payments.refunded', $kind, $sourceId, ['cents' => $cents, 'method' => $method, 'reason' => $reason, 'by' => $byName]);
        $told = $this->tell($customer, $cents, $method, $note);
        if ($told !== null) {
            DB::connection($this->context->connectionName())->table('refunds')->where('tenant_id', $this->context->id())->where('id', $id)->update(['told_by' => $told]);
        }

        return $id;
    }

    public function for(string $kind, string $sourceId): array
    {
        return array_values(DB::connection($this->context->connectionName())->table('refunds')->where('tenant_id', $this->context->id())
            ->where('kind', $kind)->where('source_id', $sourceId)->orderBy('refunded_at')->orderBy('id')->get()
            ->map(fn ($r) => ['id' => (string) $r->id, 'amount_cents' => (int) $r->amount_cents, 'method' => (string) $r->method, 'reason' => (string) $r->reason,
                'note' => $r->note, 'by_name' => (string) $r->by_name, 'refunded_at' => (string) $r->refunded_at, 'told_by' => $r->told_by])->all());
    }

    public function atTheCounter(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = DB::connection($this->context->connectionName())->table('refunds')->where('tenant_id', $this->context->id())->whereIn('method', self::AT_THE_COUNTER)
            ->where('refunded_at', '>=', CarbonImmutable::instance($from))->where('refunded_at', '<', CarbonImmutable::instance($to))
            ->groupBy('method')->selectRaw('method, SUM(amount_cents) as cents')->pluck('cents', 'method');

        return array_map(fn ($c) => (int) $c, $rows->all());
    }

    public function totalFor(string $kind, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) DB::connection($this->context->connectionName())->table('refunds')->where('tenant_id', $this->context->id())->where('kind', $kind)
            ->where('refunded_at', '>=', CarbonImmutable::instance($from))->where('refunded_at', '<', CarbonImmutable::instance($to))->sum('amount_cents');
    }

    /** @param array{name?: string, phone?: string|null, email?: string|null, what?: string} $customer */
    private function tell(array $customer, int $cents, string $method, ?string $note): ?string
    {
        $first = (string) (preg_split('/\s+/', trim((string) ($customer['name'] ?? '')))[0] ?? '');
        $how = match ($method) {
            'provider' => __('to the card or account you paid with (it can take a few working days)'),
            'store_credit' => __('as store credit'),
            'eft' => __('by EFT'),
            'mobile_money' => __('to your mobile money account'),
            default => __('in person'),
        };
        $text = (string) __('Hi :name, :business has refunded :amount for :what, :how.', ['name' => $first !== '' ? $first : __('there'),
            'business' => $this->context->current()->displayName(), 'amount' => Money::format($cents), 'what' => (string) ($customer['what'] ?? __('your purchase')), 'how' => $how])
            .($note !== null ? ' '.$note : '');
        try {
            if (($customer['phone'] ?? null) && $this->messenger->channelActive('sms') && $this->messenger->sms((string) $customer['phone'], $text, 'refund')->ok) {
                return 'sms';
            }
            if (($customer['email'] ?? null) && filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
                Mail::raw($text, fn ($m) => $m->to((string) $customer['email'])->subject((string) __('Your refund from :business', ['business' => $this->context->current()->displayName()])));

                return 'email';
            }
        } catch (Throwable $e) {
            report($e); // the refund stands even if the message couldn't go
        }

        return null;
    }
}
