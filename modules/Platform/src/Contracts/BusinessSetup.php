<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/** Modules register their part of setting a business up by talking (Sprint 23.1). */
interface BusinessSetup
{
    /** @param  class-string<SetupStep>  $step */
    public function register(string $step): void;

    /** @return list<SetupStep> in order */
    public function steps(): array;
}
