<?php

declare(strict_types=1);

namespace Wurk\Platform\Events;

/** A business's "Verified by WURK" badge changed (25.4): its published pages are re-rendered (the footer shows it). */
final readonly class BadgeChanged
{
    public function __construct(public string $tenantId) {}
}
