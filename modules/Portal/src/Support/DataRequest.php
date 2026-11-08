<?php

declare(strict_types=1);

namespace Wurk\Portal\Support;

/** One POPIA deletion request (ids only — no personal details). */
final readonly class DataRequest
{
    public function __construct(public string $id, public string $contact_id, public string $status) {}
}
