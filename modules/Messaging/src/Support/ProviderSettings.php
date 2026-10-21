<?php

declare(strict_types=1);

namespace Wurk\Messaging\Support;

use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Messaging\Models\ProviderSetting;

/**
 * Stores provider credentials encrypted. Secrets are write-only: the admin
 * never receives them back, and leaving a secret field blank keeps the saved value.
 */
final readonly class ProviderSettings
{
    public function __construct(private AuditLog $audit) {}

    public function active(string $channel): ?ProviderSetting
    {
        return ProviderSetting::query()->where('channel', $channel)->where('active', true)->first();
    }

    /**
     * @param  array<string, string|null>  $credentials
     * @param  array<string, string|null>  $settings
     */
    public function save(string $channel, string $driver, array $credentials, array $settings, bool $active, ?string $by): ProviderSetting
    {
        $definition = Drivers::get($channel, $driver) ?? throw ValidationException::withMessages(['driver' => 'Unknown provider.']);
        $existing = ProviderSetting::query()->find($channel);
        $keep = $existing !== null && $existing->driver === $driver ? $existing->credentials : [];

        $stored = [];
        foreach ($definition['fields'] as $key => $field) {
            $value = trim((string) ($credentials[$key] ?? ''));
            $stored[$key] = $value === '' ? ($keep[$key] ?? '') : $value;
            if ($active && $stored[$key] === '') {
                throw ValidationException::withMessages(["credentials.{$key}" => "{$field['label']} is required to switch this provider on."]);
            }
        }

        $cleanSettings = array_filter(
            array_map(fn ($v) => trim((string) $v), array_intersect_key($settings, $definition['settings'])),
            fn (string $v) => $v !== '',
        );

        $setting = ProviderSetting::query()->updateOrCreate(['channel' => $channel], [
            'driver' => $driver, 'credentials' => $stored, 'settings' => $cleanSettings, 'active' => $active, 'updated_by' => $by,
        ]);

        // Never write credential values to the audit trail — only which fields changed.
        $this->audit->record('provider.saved', 'provider', $channel, [
            'driver' => $driver, 'active' => $active, 'credentials_changed' => array_keys(array_filter($credentials, fn ($v) => trim((string) $v) !== '')),
        ], ['platform' => true]);

        return $setting;
    }

    /**
     * What the admin may see: driver, settings, which secrets are set (masked).
     *
     * @return array<string, mixed>|null
     */
    public function present(string $channel): ?array
    {
        $s = ProviderSetting::query()->find($channel);
        if ($s === null) {
            return null;
        }

        $fields = Drivers::get($channel, $s->driver)['fields'] ?? [];

        return [
            'driver' => $s->driver,
            'active' => $s->active,
            'settings' => $s->settings ?? [],
            'credentials' => array_map(fn (string $key) => [
                'set' => ($s->credentials[$key] ?? '') !== '',
                'hint' => ($fields[$key]['secret'] ?? true) ? (($s->credentials[$key] ?? '') === '' ? null : '••••'.substr($s->credentials[$key], -4)) : ($s->credentials[$key] ?? null),
            ], array_combine(array_keys($fields), array_keys($fields))),
            'last_test' => $s->last_tested_at === null ? null : ['at' => $s->last_tested_at->toIso8601String(), 'ok' => $s->last_test_ok, 'message' => $s->last_test_message],
        ];
    }
}
