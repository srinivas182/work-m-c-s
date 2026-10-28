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
            'eft' => ['instructions' => ['required', 'string', 'max:2000']],
            default => [],
        };
    }

    /** @return array<string, string> */
    public static function messages(string $mode): array
    {
        return [
            'merchant_id.regex' => 'Your PayFast merchant ID is a number (Settings → Integration in PayFast).',
            'merchant_key.regex' => 'Your PayFast merchant key is letters and numbers (Settings → Integration in PayFast).',
            'passphrase.required' => 'Set a passphrase in PayFast (Settings → Integration) and enter it here — it protects your payments.',
            'secret_key.regex' => "Paste your Yoco {$mode} secret key — it starts with sk_{$mode}_.",
            'instructions.required' => 'Tell people how to pay — e.g. bank, account number and branch code.',
        ];
    }

    /** @param  array<string, string>  $keys */
    public static function label(string $provider, array $keys): string
    {
        $last = fn (string $v) => '••••'.substr($v, -4);

        return match ($provider) {
            'payfast' => 'Merchant '.$last((string) ($keys['merchant_id'] ?? '')),
            'yoco' => 'Key '.$last((string) ($keys['secret_key'] ?? '')),
            'ozow' => 'Site '.(string) ($keys['site_code'] ?? ''),
            'peach' => 'Entity '.$last((string) ($keys['entity_id'] ?? '')),
            default => 'Bank details saved',
        };
    }
}
