<?php

declare(strict_types=1);

namespace Wurk\Reach\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Replies for SMS booking (25.2b), sent from the same Africa's Talking number the customer texted, so their next
 * answer comes back to WURK. (Other messages use the business's SMS provider as usual.)
 */
final class AfricasTalkingSms
{
    /**
     * @param  array<string, string>  $credentials  username, api_key
     * @param  array<string, string>  $settings  sms_number
     */
    public function send(string $to, string $text, array $credentials, array $settings): bool
    {
        $username = (string) ($credentials['username'] ?? '');
        $host = $username === 'sandbox' ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com';
        try {
            $r = Http::timeout(15)->asForm()->withHeaders(['apiKey' => (string) ($credentials['api_key'] ?? ''), 'Accept' => 'application/json'])
                ->post($host.'/version1/messaging', array_filter(['username' => $username, 'to' => $to, 'message' => $text, 'from' => $settings['sms_number'] ?? null]));
        } catch (Throwable) {
            return false;
        }

        return $r->successful() && in_array($r->json('SMSMessageData.Recipients.0.status'), ['Success', 'Sent'], true);
    }
}
