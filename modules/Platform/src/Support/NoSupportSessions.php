<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Wurk\Platform\Contracts\SupportSessions;

/** Until the Support module is installed: nobody from WURK has access to a business. */
final class NoSupportSessions implements SupportSessions
{
    public function active(string $tenantId, string $identityId): ?array
    {
        return null;
    }
}
