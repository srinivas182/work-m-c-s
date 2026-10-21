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
 * fields the admin shows. Payment gateways are stored now and used from Sprint 3.
 */
final class Drivers
{
    /** @return array<string, array<string, array{name: string, class?: class-string, fields: array<string, array{label: string, secret: bool}>, settings: array<string, string>}>> */
    public static function catalogue(): array
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
                ]],
            ],
            'email' => [
                'smtp' => ['name' => 'SMTP (Brevo relay or any provider)', 'fields' => ['username' => ['label' => 'SMTP username', 'secret' => false], 'password' => ['label' => 'SMTP password / key', 'secret' => true]], 'settings' => [
                    'host' => 'Host (Brevo: smtp-relay.brevo.com)', 'port' => 'Port (587)', 'from_address' => 'From address', 'from_name' => 'From name',
                ]],
            ],
            'payments.payfast' => ['payfast' => ['name' => 'PayFast', 'fields' => ['merchant_id' => ['label' => 'Merchant ID', 'secret' => false], 'merchant_key' => ['label' => 'Merchant key', 'secret' => true], 'passphrase' => ['label' => 'Passphrase', 'secret' => true]], 'settings' => ['sandbox' => 'Sandbox (1 = yes)']]],
            'payments.paystack' => ['paystack' => ['name' => 'Paystack', 'fields' => ['public_key' => ['label' => 'Public key', 'secret' => false], 'secret_key' => ['label' => 'Secret key', 'secret' => true]], 'settings' => []]],
            'payments.yoco' => ['yoco' => ['name' => 'Yoco', 'fields' => ['secret_key' => ['label' => 'Secret key', 'secret' => true]], 'settings' => []]],
            'payments.ozow' => ['ozow' => ['name' => 'Ozow', 'fields' => ['site_code' => ['label' => 'Site code', 'secret' => false], 'private_key' => ['label' => 'Private key', 'secret' => true], 'api_key' => ['label' => 'API key', 'secret' => true]], 'settings' => ['sandbox' => 'Sandbox (1 = yes)']]],
            'payments.peach' => ['peach' => ['name' => 'Peach Payments', 'fields' => ['entity_id' => ['label' => 'Entity ID', 'secret' => false], 'access_token' => ['label' => 'Access token', 'secret' => true]], 'settings' => ['sandbox' => 'Sandbox (1 = yes)']]],
        ];
    }

    /** @return array{name: string, class?: class-string, fields: array<string, array{label: string, secret: bool}>, settings: array<string, string>}|null */
    public static function get(string $channel, string $driver): ?array
    {
        return self::catalogue()[$channel][$driver] ?? null;
    }
}
