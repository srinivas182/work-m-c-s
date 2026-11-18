<?php

declare(strict_types=1);

namespace Wurk\Security\Support;

use Throwable;
use Wurk\Security\Contracts\PlatformSettings;

/**
 * WURK's own contact addresses (Sprint 19.6: set in Admin → Security; the server's .env values only until then):
 * where contact-form messages go, the support address customers are given, and the security contact (security.txt,
 * the Trust and Security pages). Applied over the configuration for every request, queued job and command, so
 * everything that reads config() gets the saved ones, even in long-running workers.
 */
final readonly class PlatformContacts
{
    public const KEY = 'platform.contacts';

    /** Field => the configuration value it sets. */
    public const FIELDS = [
        'contact_email' => 'wurk.contact_email',
        'support_email' => 'wurk.support_email',
        'security_email' => 'wurk.security.contact',
    ];

    public function __construct(private PlatformSettings $settings) {}

    /** @return array<string, string> */
    public function saved(): array
    {
        $saved = $this->settings->get(self::KEY);

        return is_array($saved) ? array_filter(array_map('strval', array_intersect_key($saved, self::FIELDS)), fn (string $v) => $v !== '') : [];
    }

    /** @return array<string, string> what's in use: the saved address, else the server's */
    public function current(): array
    {
        $out = [];
        foreach (self::FIELDS as $field => $key) {
            $out[$field] = $this->saved()[$field] ?? (string) config($key, '');
        }

        return $out;
    }

    /** @param array<string, string> $contacts */
    public function save(array $contacts, ?string $by): void
    {
        $this->settings->set(self::KEY, array_intersect_key($contacts, self::FIELDS), $by);
        $this->apply();
    }

    public function apply(): void
    {
        try {
            $saved = $this->saved();
        } catch (Throwable) {
            return; // no database yet (fresh install, build step): keep the .env values
        }
        foreach ($saved as $field => $value) {
            config([self::FIELDS[$field] => $value]);
        }
    }
}
