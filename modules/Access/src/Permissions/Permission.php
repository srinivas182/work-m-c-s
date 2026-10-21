<?php

declare(strict_types=1);

namespace Wurk\Access\Permissions;

final readonly class Permission
{
    public const PLATFORM = 'platform';

    public const BUSINESS = 'business';

    public function __construct(public string $key, public string $label, public string $scope, public string $module) {}
}
