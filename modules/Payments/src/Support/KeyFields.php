<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

/** Each method's keys: what to ask for, how to check it, and a label that's safe to show. Used by WURK and businesses. */
final class KeyFields
{
    /** @return array<string, list<string>> */
    public static function rules(string $provider, string $mode): array
    {
        return match ($provider) {
            'payfast' => ['merchant_id' => ['required', 'regex:/^\d{6,12}$/'], 'merchant_key' => ['required', 'regex:/^[A-Za-z0-9]{10,20}$/'], 'passphrase' => ['required', 'string', 'min:6', 'max:100']],
            'yoco' => ['secret_key' => ['required', 'regex:/^sk_'.$mode.'_[A-Za-z0-9]+$/']],
            'ozow' => ['site_code' => ['required', 'string', 'max:40'], 'private_key' => ['required', 'string', 'max:200'], 'api_key' => ['required', 'string', 'max:200']],
            'peach' => ['entity_id' => ['required', 'string', 'max:64'], 'client_id' => ['required', 'string', 'max:200'], 'client_secret' => ['required', 'string', 'max:200'], 'merchant_id' => ['required', 'string', 'max:64'], 'secret_token' => ['required', 'string', 'max:200']],
            'paystack' => ['secret_key' => ['required', 'regex:/^sk_'.$mode.'_[A-Za-z0-9]+$/']],
            'flutterwave' => ['secret_key' => ['required', 'regex:/^FLWSECK'.($mode === 'test' ? '_TEST' : '').'-[A-Za-z0-9]+-X$/'], 'secret_hash' => ['required', 'string', 'min:16', 'max:200']],
            'mpesa' => ['shortcode' => ['required', 'regex:/^\d{5,7}$/'], 'till' => ['nullable', 'regex:/^\d{5,8}$/'], 'passkey' => ['required', 'string', 'max:200'],
                'consumer_key' => ['required', 'string', 'max:200'], 'consumer_secret' => ['required', 'string', 'max:200']],
            'mtn_momo' => ['api_user' => ['required', 'uuid'], 'api_key' => ['required', 'string', 'max:200'], 'subscription_key' => ['required', 'string', 'max:200'],
                'environment' => $mode === 'live' ? ['required', 'regex:/^mtn[a-z]+$/'] : ['nullable', 'string', 'max:40']],
            'airtel_money' => ['client_id' => ['required', 'string', 'max:200'], 'client_secret' => ['required', 'string', 'max:200']],
            'eft' => ['instructions' => ['required', 'string', 'max:2000']],
            default => [],
        };
    }

    /** @return array<string, string> */
    public static function messages(string $mode, string $provider = 'yoco'): array
    {
        $name = PlatformMethods::NAMES[$provider] ?? 'Yoco';

        return [
            'merchant_id.regex' => 'Your PayFast merchant ID is a number (Settings → Integration in PayFast).',
            'merchant_key.regex' => 'Your PayFast merchant key is letters and numbers (Settings → Integration in PayFast).',
            'passphrase.required' => 'Set a passphrase in PayFast (Settings → Integration) and enter it here — it protects your payments.',
            'secret_key.regex' => "Paste your {$name} {$mode} secret key — it starts with sk_{$mode}_.",
            'secret_hash.min' => 'Set a secret hash of at least 16 characters in Flutterwave (Settings → Webhooks) and enter it here.',
            'shortcode.regex' => 'Your M-Pesa shortcode is the Paybill or store number (5 to 7 digits).',
            'till.regex' => 'A till number is 5 to 8 digits. Leave it empty for a Paybill.',
            'api_user.uuid' => 'Your MoMo API user is an ID like 3f2b…-…, from the MTN MoMo developer portal.',
            'environment.regex' => 'Your MTN market, as MTN names it (e.g. mtnghana or mtnuganda).',
            'instructions.required' => 'Tell people how to pay — e.g. bank, account number and branch code.',
        ];
    }

    /** @param  array<string, string>  $keys */
    public static function label(string $provider, array $keys): string
    {
        $last = fn (string $v) => '••••'.substr($v, -4);

        return match ($provider) {
            'payfast' => 'Merchant '.$last((string) ($keys['merchant_id'] ?? '')),
            'yoco', 'paystack' => 'Key '.$last((string) ($keys['secret_key'] ?? '')),
            'ozow' => 'Site '.(string) ($keys['site_code'] ?? ''),
            'peach' => 'Entity '.$last((string) ($keys['entity_id'] ?? '')),
            'flutterwave' => 'Key '.$last((string) ($keys['secret_key'] ?? '')),
            'mpesa' => ($keys['till'] ?? '') !== '' ? 'Till '.(string) $keys['till'] : 'Paybill '.(string) ($keys['shortcode'] ?? ''),
            'mtn_momo' => 'API user '.$last((string) ($keys['api_user'] ?? '')),
            'airtel_money' => 'Client '.$last((string) ($keys['client_id'] ?? '')),
            default => 'Bank details saved',
        };
    }
}
