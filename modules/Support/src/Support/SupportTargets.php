<?php

declare(strict_types=1);

namespace Wurk\Support\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Security\Contracts\PlatformSettings;

/**
 * How soon WURK replies first (decision B): hours by package, counted in WURK's business hours (weekdays 08:00–17:00,
 * South African time). Shown to the business when they open a ticket; breaches flagged in the inbox.
 */
final readonly class SupportTargets
{
    public const DEFAULTS = [
        'targets' => ['starter' => 24, 'growth' => 8, 'pro' => 4],
        'managed' => 2,          // any managed package
        'other' => 24,           // packages not listed, or no package yet
        'business_hours' => ['tz' => 'Africa/Johannesburg', 'start' => 8, 'end' => 17, 'days' => [1, 2, 3, 4, 5]], // ISO weekdays
        'inbox_email' => null,   // where new tickets and replies are emailed (null: config wurk.support_email)
    ];

    public function __construct(private PlatformSettings $settings, private Catalog $catalog) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_replace_recursive(self::DEFAULTS, (array) $this->settings->get('support', []));
    }

    /** @return array{hours: int, package_key: string|null} */
    public function forTenant(string $tenantId): array
    {
        $all = $this->all();
        $sub = $this->catalog->subscriptionOf($tenantId);
        if ($sub !== null && $sub['package_type'] === 'managed') {
            return ['hours' => (int) $all['managed'], 'package_key' => $sub['package_key']];
        }

        return ['hours' => (int) ($all['targets'][$sub['package_key'] ?? ''] ?? $all['other']), 'package_key' => $sub['package_key'] ?? null];
    }

    /** When a first reply is due: $hours of business hours after $from. */
    public function due(DateTimeInterface $from, int $hours): CarbonImmutable
    {
        $bh = (array) $this->all()['business_hours'];
        $tz = (string) $bh['tz'];
        [$open, $close] = [(int) $bh['start'], (int) $bh['end']];
        $days = array_map('intval', (array) $bh['days']);
        $t = CarbonImmutable::instance($from)->setTimezone($tz);
        $left = max(0, $hours) * 60;
        for ($guard = 0; $guard < 400; $guard++) {
            if (! in_array($t->dayOfWeekIso, $days, true) || $t->hour >= $close) {
                $t = $t->addDay()->setTime($open, 0);

                continue;
            }
            if ($t->hour < $open) {
                $t = $t->setTime($open, 0);
            }
            $available = (int) $t->diffInMinutes($t->setTime($close, 0));
            if ($left <= $available) {
                return $t->addMinutes($left)->setTimezone((string) config('app.timezone'));
            }
            $left -= $available;
            $t = $t->addDay()->setTime($open, 0);
        }

        return $t->setTimezone((string) config('app.timezone'));
    }

    /** "8 business hours (weekdays 08:00–17:00)" — for the business. */
    public function describe(int $hours): string
    {
        $bh = (array) $this->all()['business_hours'];

        return $hours.' business '.($hours === 1 ? 'hour' : 'hours').sprintf(' (weekdays %02d:00–%02d:00 South African time)', (int) $bh['start'], (int) $bh['end']);
    }

    public function inboxEmail(): ?string
    {
        $email = $this->all()['inbox_email'] ?? null;

        return is_string($email) && $email !== '' ? $email : (config('wurk.support_email') ?: null);
    }
}
