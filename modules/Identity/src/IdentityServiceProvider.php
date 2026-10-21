<?php

declare(strict_types=1);

namespace Wurk\Identity;

use Wurk\Identity\Console\CreateIdentityCommand;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        //
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'identity');

        if ($this->app->runningInConsole()) {
            $this->commands([CreateIdentityCommand::class]);
        }
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return [];
    }
}
