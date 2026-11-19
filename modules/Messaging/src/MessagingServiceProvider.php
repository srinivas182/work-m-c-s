<?php

declare(strict_types=1);

namespace Wurk\Messaging;

use Illuminate\Console\Scheduling\Schedule;
use Wurk\Identity\Contracts\TextMessenger;
use Wurk\Messaging\Console\SendOwnerSummariesCommand;
use Wurk\Messaging\Console\SyncWhatsAppTemplatesCommand;
use Wurk\Messaging\Contracts\DeliveryLog;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Messaging\Contracts\WhatsAppActions;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Messaging\Contracts\WurkWhatsApp;
use Wurk\Messaging\Otp\PhoneOtpChannel;
use Wurk\Messaging\Otp\SmsTextMessenger;
use Wurk\Messaging\Support\Deliveries;
use Wurk\Messaging\Support\EloquentProviderCredentials;
use Wurk\Messaging\Support\MailConfigurator;
use Wurk\Messaging\Support\MessengerService;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Messaging\Support\Templates;
use Wurk\Messaging\Support\WhatsAppActionRegistry;
use Wurk\Messaging\Support\WhatsAppBusinessService;
use Wurk\Messaging\Support\WurkNumber;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class MessagingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DeliveryLog::class, Deliveries::class); // 15.5c
        $this->app->singleton(Templates::class);
        $this->app->alias(Templates::class, MessageTemplates::class);
        $this->app->singleton(ProviderSettings::class);
        $this->app->singleton(Messenger::class, MessengerService::class);
        $this->app->bind(WurkWhatsApp::class, WurkNumber::class); // 25.6: the help line
        $this->app->singleton(TextMessenger::class, SmsTextMessenger::class);
        $this->app->singleton(ProviderCredentials::class, EloquentProviderCredentials::class);
        $this->app->bind(WhatsAppBusiness::class, WhatsAppBusinessService::class); // 21.2
        $this->app->singleton(WhatsAppActions::class, WhatsAppActionRegistry::class); // 21.5
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [
            ['key' => 'providers.manage', 'label' => 'Configure SMS, WhatsApp, email and payment providers', 'scope' => 'platform'],
            ['key' => 'messages.manage', 'label' => 'Word the messages customers receive, and send tests'],
        ];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Providers', 'route' => 'admin.providers', 'icon' => 'message-circle', 'section' => 'Settings', 'permission' => 'providers.manage', 'order' => 920],
            ['label' => 'Messages', 'route' => 'tenant.messages', 'icon' => 'message-circle', 'section' => 'Settings', 'permission' => 'messages.manage', 'order' => 835],
            ['label' => 'WhatsApp', 'route' => 'tenant.whatsapp', 'icon' => 'message-circle', 'section' => 'Settings', 'permission' => 'messages.manage', 'order' => 836],
        ];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'messaging');
        if ($this->app->runningInConsole()) {
            $this->commands([SyncWhatsAppTemplatesCommand::class, SendOwnerSummariesCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $s): void {
            $s->command('messaging:sync-whatsapp-templates')->hourlyAt(17)->onOneServer()->withoutOverlapping(); // 21.5
            $s->command('messaging:owner-summaries')->everyFifteenMinutes()->onOneServer()->withoutOverlapping(); // 21.6
        });
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
