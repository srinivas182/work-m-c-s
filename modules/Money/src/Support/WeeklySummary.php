<?php

declare(strict_types=1);

namespace Wurk\Money\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Contracts\MoneyOwed;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Monday's "how did I do" on WhatsApp (Sprint 23.5b, decision F): last week's takings against the week before, the best
 * seller, new and returning customers, who owes, and one suggestion. From WURK's number (an approved template), to each
 * owner's verified mobile, charged to the business like a WhatsApp message. On by default; STOP turns it off.
 */
final readonly class WeeklySummary
{
    /** "Hi {{1}}, last week at {{2}}: {{3}} {{4}} {{5}} Tip: {{6}} Reply STOP to stop these Monday summaries." */
    public const TEMPLATE = 'wurk_weekly_summary';

    public function __construct(private TenantContext $context, private TeamDirectory $team, private PeopleDirectory $people, private BusinessReports $reports,
        private MoneyOwed $owed, private Messenger $messenger, private CreditWallet $wallet, private MoneyBook $book, private AuditLog $audit) {}

    /**
     * The summary's lines for last week (Monday to Sunday, the business's time).
     *
     * @return array{took: string, customers: string, owed: string, tip: string}
     */
    public function lines(): array
    {
        $tz = $this->context->current()->timezone();
        $monday = CarbonImmutable::now($tz)->startOfWeek()->subWeek();
        $sections = $this->reports->sections(new ReportPeriod($monday, $monday->addWeek()->subSecond(), null, true));
        $metric = function (string $label) use ($sections): ?array {
            foreach ($sections as $s) {
                foreach ((array) ($s['metrics'] ?? []) as $m) {
                    if (($m['label'] ?? '') === $label) {
                        return $m;
                    }
                }
            }

            return null;
        };
        $highlights = array_merge(...array_map(fn ($s) => array_values((array) ($s['highlights'] ?? [])), $sections ?: [[]]));
        $took = $metric('Taken (after refunds)');
        $now = (int) ($took['value'] ?? 0);
        $before = (int) ($took['previous'] ?? 0);
        $change = $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
        $tookLine = 'You took '.MoneyBook::rand($now).($change === null ? '.' : ($change === 0 ? ', the same as the week before.' : ', '.abs($change).'% '.($change > 0 ? 'up on' : 'down on').' the week before.'));
        $best = collect($highlights)->first(fn ($h) => str_contains((string) $h, 'best seller') || str_contains((string) $h, 'most booked'));
        if ($best !== null) {
            $tookLine .= ' '.$best;
        }
        $new = (int) ($metric('New customers')['value'] ?? 0);
        $seen = (int) ($metric('Customers seen')['value'] ?? 0);
        $owed = $this->owed->all();
        $tip = collect($highlights)->first(fn ($h) => str_contains((string) $h, 'win-back') || str_contains((string) $h, 'haven’t been back'))
            ?? ($owed !== [] ? 'Send a reminder to the customers who owe you — it’s one tap in Who owes me.' : 'Share your booking link on WhatsApp Status this week.');

        return [
            'took' => $tookLine,
            'customers' => $seen === 0 ? 'No customers recorded.' : "{$new} new ".($new === 1 ? 'customer' : 'customers').' and '.max(0, $seen - $new).' came back.',
            'owed' => $owed === [] ? 'Nobody owes you money.' : count($owed).' '.(count($owed) === 1 ? 'customer owes' : 'customers owe').' you '.MoneyBook::rand(array_sum(array_column($owed, 'amount_cents'))).'.',
            'tip' => (string) $tip,
        ];
    }

    /** Send it to the business's owners (once a week). Returns how many got it. */
    public function send(): int
    {
        if (! $this->book->settings()['weekly_summary']) {
            return 0;
        }
        $db = DB::connection($this->context->connectionName());
        $sentAt = $db->table('money_settings')->where('tenant_id', $this->context->id())->value('weekly_summary_sent_at');
        if ($sentAt !== null && CarbonImmutable::parse((string) $sentAt)->gt(now()->subDays(6))) {
            return 0; // once a week
        }
        $lines = $this->lines();
        $sent = 0;
        foreach ($this->team->roster() as $m) {
            if (! $m['active'] || ($m['role'] ?? '') !== 'owner' || ($phone = $this->people->verifiedPhone($m['identity_id'])) === null) {
                continue;
            }
            $digits = (string) preg_replace('/\D/', '', $phone);
            if (DB::table('money_weekly_stops')->where('phone', $digits)->exists()) {
                continue;
            }
            $key = 'weekly-summary:'.$m['identity_id'].':'.now()->format('o-W');
            try {
                $this->wallet->charge('whatsapp', $key, 'Monday summary (WhatsApp)');
            } catch (InsufficientCredits) {
                continue;
            }
            try {
                $ok = $this->messenger->whatsappTemplate($phone, self::TEMPLATE, [(string) strtok((string) $m['name'], ' ') ?: 'there', $this->context->current()->displayName(),
                    $lines['took'], $lines['customers'], $lines['owed'], $lines['tip']], 'weekly_summary')->ok;
            } catch (Throwable) {
                $ok = false;
            }
            if ($ok) {
                $sent++;
            } else {
                $this->wallet->refund($key, 'Monday summary not delivered');
            }
        }
        $db->table('money_settings')->updateOrInsert(['tenant_id' => $this->context->id()], ['weekly_summary_sent_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record('money.weekly_summary_sent', 'money_settings', $this->context->id(), ['sent' => $sent]);

        return $sent;
    }

    /** An owner replied STOP on WURK's number: no more Monday summaries to that number. */
    public static function stop(string $from): void
    {
        $digits = (string) preg_replace('/\D/', '', $from);
        if ($digits !== '') {
            DB::table('money_weekly_stops')->insertOrIgnore(['phone' => $digits, 'stopped_at' => now()]);
        }
    }

    public static function resume(string $phone): void
    {
        DB::table('money_weekly_stops')->where('phone', (string) preg_replace('/\D/', '', $phone))->delete();
    }
}
