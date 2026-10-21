<?php

declare(strict_types=1);

namespace Wurk\Access\Events;

/**
 * Any change to who can do what: role assigned or removed, branches changed,
 * custom role created/changed/deleted, WURK staff role granted or removed.
 */
final readonly class AccessChanged
{
    /** @param  array<string, mixed>  $details */
    public function __construct(
        public string $action,
        public string $subjectType,
        public string $subjectId,
        public array $details = [],
        public bool $platform = false,
    ) {}
}
