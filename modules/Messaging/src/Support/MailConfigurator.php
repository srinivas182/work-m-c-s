<?php

declare(strict_types=1);

namespace Wurk\Messaging\Support;

use Illuminate\Mail\MailManager;
use Wurk\Messaging\Models\ProviderSetting;

/** Points Laravel's mailer at the active email provider (SMTP / Brevo relay). */
final readonly class MailConfigurator
{
    public function __construct(private ProviderSettings $providers, private MailManager $mail) {}

    public function applyActive(): void
    {
        $active = $this->providers->active('email');
        if ($active !== null) {
            $this->apply($active);
        }
    }

    public function apply(ProviderSetting $setting): void
    {
        $settings = $setting->settings ?? [];
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $settings['host'] ?? 'smtp-relay.brevo.com',
            'mail.mailers.smtp.port' => (int) ($settings['port'] ?? 587),
            'mail.mailers.smtp.username' => $setting->credentials['username'] ?? null,
            'mail.mailers.smtp.password' => $setting->credentials['password'] ?? null,
            'mail.from.address' => $settings['from_address'] ?? config('mail.from.address'),
            'mail.from.name' => $settings['from_name'] ?? 'WURK',
        ]);
        $this->mail->purge('smtp');
    }
}
