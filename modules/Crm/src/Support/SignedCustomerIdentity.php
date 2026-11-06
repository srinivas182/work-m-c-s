<?php

declare(strict_types=1);

namespace Wurk\Crm\Support;

use Wurk\Crm\Contracts\CustomerIdentity;
use Wurk\Tenancy\Contracts\TenantContext;

/** Signed with the app key (its own purpose); bound to the business and customer; valid 5 minutes. */
final readonly class SignedCustomerIdentity implements CustomerIdentity
{
    public const MINUTES = 5;

    public function __construct(private TenantContext $context) {}

    public function tokenFor(string $contactId): string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode(['t' => $this->context->id(), 'c' => $contactId, 'e' => now()->addMinutes(self::MINUTES)->getTimestamp()])), '+/', '-_'), '=');

        return self::PREFIX.$body.'.'.$this->sign($body);
    }

    public function contactFromToken(string $token): ?string
    {
        $token = trim($token);
        if (! str_starts_with($token, self::PREFIX)) {
            return null;
        }
        [$body, $sig] = array_pad(explode('.', substr($token, strlen(self::PREFIX)), 2), 2, '');
        if ($body === '' || ! hash_equals($this->sign($body), $sig)) {
            return null;
        }
        $d = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        if (! is_array($d) || ($d['t'] ?? null) !== $this->context->id() || (int) ($d['e'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return is_string($d['c'] ?? null) ? $d['c'] : null;
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, 'customer-id|'.(string) config('app.key'));
    }
}
