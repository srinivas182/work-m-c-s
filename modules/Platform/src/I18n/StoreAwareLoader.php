<?php

declare(strict_types=1);

namespace Wurk\Platform\I18n;

use Illuminate\Contracts\Translation\Loader;

/** Laravel's translation loader, with reviewed and drafted translations (Sprint 24.2) over the JSON files. */
final readonly class StoreAwareLoader implements Loader
{
    public function __construct(private Loader $files) {}

    /** @return array<array-key, mixed> */
    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->files->load($locale, $group, $namespace);

        return $group === '*' && $namespace === '*' ? [...$lines, ...TranslationStore::overrides((string) $locale)] : $lines;
    }

    public function addNamespace($namespace, $hint)
    {
        $this->files->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->files->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->files->namespaces();
    }
}
