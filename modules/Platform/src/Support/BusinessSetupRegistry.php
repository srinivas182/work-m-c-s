<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Wurk\Platform\Contracts\BusinessSetup;
use Wurk\Platform\Contracts\SetupStep;

final class BusinessSetupRegistry implements BusinessSetup
{
    /** @var list<class-string<SetupStep>> */
    private array $steps = [];

    public function register(string $step): void
    {
        if (! in_array($step, $this->steps, true)) {
            $this->steps[] = $step;
        }
    }

    public function steps(): array
    {
        $steps = array_map(fn (string $class): SetupStep => app($class), $this->steps);
        usort($steps, fn (SetupStep $a, SetupStep $b) => $a->order() <=> $b->order());

        return $steps;
    }
}
