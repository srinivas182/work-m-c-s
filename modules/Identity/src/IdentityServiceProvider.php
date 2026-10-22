<?php

declare(strict_types=1);

namespace Wurk\Identity;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Wurk\Identity\Console\CreateIdentityCommand;
use Wurk\Identity\Contracts\Accounts;
use Wurk\Identity\Contracts\Invitations;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Identity\Contracts\TextMessenger;
use Wurk\Identity\Contracts\Verification;
use Wurk\Identity\Http\Middleware\EnsurePlatformStaff;
use Wurk\Identity\Invites\InvitationService;
use Wurk\Identity\Listeners\InviteBusinessOwner;
use Wurk\Identity\Onboarding\AccountService;
use Wurk\Identity\Onboarding\VerificationService;
use Wurk\Identity\Otp\LogTextMessenger;
use Wurk\Identity\People\EloquentPeopleDirectory;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Tenancy\Events\OwnerInvitationRequested;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Invitations::class, InvitationService::class);
        $this->app->singleton(PeopleDirectory::class, EloquentPeopleDirectory::class);
        $this->app->singletonIf(TextMessenger::class, LogTextMessenger::class);
        $this->app->singleton(Verification::class, VerificationService::class);
        $this->app->singleton(Accounts::class, AccountService::class);
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'identity');
        Event::listen(OwnerInvitationRequested::class, InviteBusinessOwner::class);
        // Published for other modules' admin routes: active WURK staff with an authenticator.
        $this->app->make(Router::class)->aliasMiddleware('staff', EnsurePlatformStaff::class);

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
