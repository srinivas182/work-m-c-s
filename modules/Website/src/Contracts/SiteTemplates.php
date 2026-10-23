<?php

declare(strict_types=1);

namespace Wurk\Website\Contracts;

/** What signup may ask the website module: which templates are offered, and apply one. */
interface SiteTemplates
{
    /** @return list<array{key: string, name: string, description: string|null, icon: string, templates: list<array{key: string, name: string, description: string|null}>}> */
    public function catalogue(): array;

    public function offers(string $categoryKey, string $templateKey): bool;

    /** Builds the current business's site from the template (run inside its tenant context). */
    public function apply(string $templateKey): void;
}
