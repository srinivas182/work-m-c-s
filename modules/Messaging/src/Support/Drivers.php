<?php

declare(strict_types=1);

namespace Wurk\Messaging\Support;

use Wurk\Messaging\Drivers\Sms\BrevoSms;
use Wurk\Messaging\Drivers\Sms\BulkSms;
use Wurk\Messaging\Drivers\Sms\ClickatellSms;
use Wurk\Messaging\Drivers\Sms\SmsPortalSms;
use Wurk\Messaging\Drivers\Sms\TwilioSms;
use Wurk\Messaging\Drivers\WhatsApp\MetaCloudWhatsApp;

/**
 * Every provider WURK supports, per channel, with the credential and setting
 * fields the admin shows. Every key is encrypted, and has a "Test connection" (19.6).
 */
final class Drivers
{
    /** @return array<string, array<string, array{name: string, class?: class-string, fields: array<string, array{label: string, secret: bool}>, settings: array<string, string>}>> */
    public static function catalogue(): array
    {
        // Every message provider records what a message costs WURK, for the package cost tip.
        $catalogue = self::providers();
        foreach (['sms', 'whatsapp', 'email'] as $channel) {
            foreach ($catalogue[$channel] as $key => $driver) {
                $catalogue[$channel][$key]['settings']['cost_zar'] = 'Your cost per message in rands (for pricing estimates)';
            }
        }

        return $catalogue;
    }

    /** @return array<string, array<string, array{name: string, class?: class-string, fields: array<string, array{label: string, secret: bool}>, settings: array<string, string>}>> */
    private static function providers(): array
    {
        $smsSettings = ['sender' => 'Sender ID or number'];

        return [
            'sms' => [
                'smsportal' => ['name' => 'SMSPortal', 'class' => SmsPortalSms::class, 'fields' => SmsPortalSms::fields(), 'settings' => ['test_mode' => 'Test mode (1 = validate only, nothing sent)']],
                'clickatell' => ['name' => 'Clickatell', 'class' => ClickatellSms::class, 'fields' => ClickatellSms::fields(), 'settings' => []],
                'bulksms' => ['name' => 'BulkSMS', 'class' => BulkSms::class, 'fields' => BulkSms::fields(), 'settings' => []],
                'brevo' => ['name' => 'Brevo', 'class' => BrevoSms::class, 'fields' => BrevoSms::fields(), 'settings' => $smsSettings],
                'twilio' => ['name' => 'Twilio', 'class' => TwilioSms::class, 'fields' => TwilioSms::fields(), 'settings' => $smsSettings + ['messaging_service_sid' => 'Messaging service SID (optional)', 'status_callback' => 'Status callback URL (optional)']],
            ],
            'whatsapp' => [
                'meta' => ['name' => 'Meta WhatsApp Cloud API', 'class' => MetaCloudWhatsApp::class, 'fields' => MetaCloudWhatsApp::fields(), 'settings' => [
                    'phone_number_id' => 'Phone number ID', 'api_version' => 'Graph API version (default '.MetaCloudWhatsApp::DEFAULT_VERSION.')',
                    'verify_token' => 'Webhook verify token', 'otp_template' => 'Sign-in code template name', 'otp_language' => 'Template language (e.g. en)',
                    // Sprint 21.1: businesses connect their own numbers through Embedded Signup (WURK as Tech Provider).
                    'app_id' => 'Meta app ID (businesses connect their own numbers)', 'signup_config_id' => 'Embedded Signup configuration ID',
                ]],
            ],
            'email' => [
                'smtp' => ['name' => 'SMTP (Brevo relay or any provider)', 'fields' => ['username' => ['label' => 'SMTP username', 'secret' => false], 'password' => ['label' => 'SMTP password / key', 'secret' => true]], 'settings' => [
                    'host' => 'Host (Brevo: smtp-relay.brevo.com)', 'port' => 'Port (587)', 'from_address' => 'From address', 'from_name' => 'From name',
                ]],
            ],
            // How WURK collects its own subscriptions (Billing). WURK's keys for the payment methods businesses offer
            // (PayFast, Yoco, Ozow, Peach) are set in Admin → Payment methods, where they're checked when saved (19.6).
            'payments.paystack' => ['paystack' => ['name' => 'Paystack', 'fields' => ['public_key' => ['label' => 'Public key', 'secret' => false], 'secret_key' => ['label' => 'Secret key', 'secret' => true]], 'settings' => []]],
            // WURK AI providers (Ai module): one secret key each; the model per task is chosen in AI settings.
            // Video meetings (Sprint 9): businesses connect their own accounts through WURK's apps.
            'meetings.google' => ['google' => ['name' => 'Google (Meet)', 'fields' => ['client_id' => ['label' => 'OAuth client ID', 'secret' => false], 'client_secret' => ['label' => 'OAuth client secret', 'secret' => true]], 'settings' => []]],
            'meetings.zoom' => ['zoom' => ['name' => 'Zoom', 'fields' => ['client_id' => ['label' => 'OAuth client ID', 'secret' => false], 'client_secret' => ['label' => 'OAuth client secret', 'secret' => true]], 'settings' => []]],
            'ai.anthropic' => ['anthropic' => ['name' => 'Anthropic (Claude)', 'fields' => ['api_key' => ['label' => 'API key', 'secret' => true]], 'settings' => []]],
            'ai.google' => ['google' => ['name' => 'Google (Gemini)', 'fields' => ['api_key' => ['label' => 'API key', 'secret' => true]], 'settings' => []]],
            'ai.openai' => ['openai' => ['name' => 'OpenAI', 'fields' => ['api_key' => ['label' => 'API key', 'secret' => true]], 'settings' => []]],
        ];
    }

    /** @return array{name: string, class?: class-string, fields: array<string, array{label: string, secret: bool}>, settings: array<string, string>}|null */
    public static function get(string $channel, string $driver): ?array
    {
        return self::catalogue()[$channel][$driver] ?? null;
    }
}
