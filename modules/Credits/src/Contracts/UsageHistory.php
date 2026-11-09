<?php

declare(strict_types=1);

namespace Wurk\Credits\Contracts;

/** What each business used, per channel, over a period (Sprint 15.5a — from the nightly roll-up). */
interface UsageHistory
{
    /** @return array<string, array<string, array{credits_milli: int, messages: int}>> tenant id => channel => usage */
    public function between(\DateTimeInterface $from, \DateTimeInterface $to): array;
}
