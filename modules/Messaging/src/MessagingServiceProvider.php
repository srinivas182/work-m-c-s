<?php

declare(strict_types=1);

namespace Wurk\Messaging;

use Wurk\Identity\Contracts\TextMessenger;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Messaging\Otp\PhoneOtpChannel;
use Wurk\Messaging\Otp\SmsTextMessenger;
use Wurk\Messaging\Support\EloquentProviderCredentials;
use Wurk\Messaging\Support\MailConfigurator;
use Wurk\Messaging\Support\MessengerService;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class MessagingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderSettings::class);
        $this->app->singleton(Messenger::class, MessengerService::class);
        $this->app->singleton(TextMessenger::class, SmsTextMessenger::class);
        $this->app->singleton(ProviderCredentials::class, EloquentProviderCredentials::class);
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [['key' => 'providers.manage', 'label' => 'Configure SMS, WhatsApp, email and payment providers', 'scope' => 'platform']];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [['label' => 'Providers', 'route' => 'admin.providers', 'icon' => 'message-circle', 'section' => 'Settings', 'permission' => 'providers.manage', 'order' => 920]];
    }

    protected function bootModule(): void
    {
        // Sign-in codes to mobile numbers go through the active WhatsApp / SMS provider.
        config(['wurk.identity.otp.channels.phone' => PhoneOtpChannel::class]);

        // Use the email provider WURK staff switched on, once the database is reachable.
        $this->app->booted(function (): void {
            try {
                $this->app->make(MailConfigurator::class)->applyActive();
            } catch (\Throwable) {
                // No database yet (fresh install, build step): keep the .env mail settings.
            }
        });
    }
}
