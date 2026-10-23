<?php

declare(strict_types=1);

namespace Wurk\Website\Exceptions;

use RuntimeException;

/** Someone else saved this page since it was opened; never overwrite their work silently. */
final class DraftConflict extends RuntimeException
{
    public function __construct(public readonly int $currentRevision, public readonly ?string $updatedBy)
    {
        parent::__construct('This page was changed by someone else while you were editing.');
    }
}
