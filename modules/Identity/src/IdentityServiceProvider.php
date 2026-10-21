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
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [
            ['key' => 'staff.manage', 'label' => 'Manage WURK staff accounts', 'scope' => 'platform'],
            ['key' => 'team.view', 'label' => 'See the team'],
            ['key' => 'team.manage', 'label' => 'Invite and manage team members'],
        ];
    }
}
