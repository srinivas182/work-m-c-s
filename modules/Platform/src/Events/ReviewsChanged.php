<?php

declare(strict_types=1);

namespace Wurk\Platform\Events;

/** A business's reviews changed (a new one, a reply, hidden or shown again): its pages showing reviews are re-rendered (25.3). */
final readonly class ReviewsChanged
{
    public function __construct(public string $tenantId) {}
}
