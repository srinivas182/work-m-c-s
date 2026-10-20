<?php

declare(strict_types=1);

namespace Wurk\Platform\Modules;

use Wurk\Platform\Modules\Exceptions\InvalidModuleManifest;

/**
 * Immutable description of a module, read from its module.json.
 */
final readonly class ModuleManifest
{
    /**
     * @param  list<string>  $dependsOn
     */
    public function __construct(
        public string $name,
        public string $key,
        public string $version,
        public string $description,
        public bool $core,
        public ?string $entitlement,
        public array $dependsOn,
        public string $path,
    ) {}

    public static function fromFile(string $file): self
    {
        if (! is_file($file)) {
            throw InvalidModuleManifest::missing($file);
        }

        $contents = file_get_contents($file);

        try {
            $data = json_decode((string) $contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw InvalidModuleManifest::malformed($file, $e->getMessage());
        }

        if (! is_array($data)) {
            throw InvalidModuleManifest::malformed($file, 'root must be an object');
        }

        $name = self::requireString($data, 'name', $file);
        $key = self::requireString($data, 'key', $file);

        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            throw InvalidModuleManifest::invalid($file, 'name must be StudlyCase');
        }

        if (preg_match('/^[a-z][a-z0-9-]*$/', $key) !== 1) {
            throw InvalidModuleManifest::invalid($file, 'key must be lowercase kebab-case');
        }

        if (basename(dirname($file)) !== $name) {
            throw InvalidModuleManifest::invalid($file, "name \"{$name}\" must match its directory name");
        }

        $dependsOn = $data['depends_on'] ?? [];

        if (! is_array($dependsOn) || array_filter($dependsOn, fn ($d) => ! is_string($d)) !== []) {
            throw InvalidModuleManifest::invalid($file, 'depends_on must be a list of module keys');
        }

        $entitlement = $data['entitlement'] ?? null;

        if ($entitlement !== null && ! is_string($entitlement)) {
            throw InvalidModuleManifest::invalid($file, 'entitlement must be a string or null');
        }

        return new self(
            name: $name,
            key: $key,
            version: self::requireString($data, 'version', $file),
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            core: (bool) ($data['core'] ?? false),
            entitlement: $entitlement,
            dependsOn: array_values($dependsOn),
            path: dirname($file),
        );
    }

    /**
     * Fully qualified class name of the module's service provider (convention-based).
     */
    public function providerClass(): string
    {
        return "Wurk\\{$this->name}\\{$this->name}ServiceProvider";
    }

    public function namespace(): string
    {
        return "Wurk\\{$this->name}";
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function requireString(array $data, string $field, string $file): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw InvalidModuleManifest::invalid($file, "\"{$field}\" is required");
        }

        return $value;
    }
}
