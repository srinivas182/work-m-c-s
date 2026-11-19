<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Wurk\Platform\Contracts\PushNotifications;

/** Without the Push module, nothing is sent. */
final class NoPushNotifications implements PushNotifications
{
    public function notify(string $kind, string $title, string $body, string $url, ?string $permission = null, array $also = [], ?string $except = null, ?string $branchId = null): void {}
}
