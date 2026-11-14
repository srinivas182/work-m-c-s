<?php

declare(strict_types=1);

namespace Wurk\Platform;

/**
 * The three queues (Sprint 18.3a). Workers take them in this order — `queue:work --queue=critical,default,bulk` — so
 * a customer's booking confirmation never waits behind another business's 50,000-message campaign.
 *
 * - critical: someone is waiting for it right now (booking messages)
 * - default: ordinary background work
 * - bulk: big, patient work (campaigns — one batch at a time per campaign, exports, AI explanations)
 */
final class Queues
{
    public const CRITICAL = 'critical';

    public const DEFAULT = 'default';

    public const BULK = 'bulk';

    /** In the order workers should take them. */
    public const ALL = [self::CRITICAL, self::DEFAULT, self::BULK];

    /** For `queue:work --queue=…`. */
    public static function workerList(): string
    {
        return implode(',', self::ALL);
    }
}
