<?php

declare(strict_types=1);

namespace Wurk\Audit\Chain;

use LogicException;

/** Audit events can be written once and never changed or removed by the application. */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit events are append-only and cannot be deleted.'));
    }
}
