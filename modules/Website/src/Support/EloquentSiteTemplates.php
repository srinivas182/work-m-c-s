<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Wurk\Website\Contracts\SiteTemplates;

final readonly class EloquentSiteTemplates implements SiteTemplates
{
    public function __construct(private Templates $templates, private SiteBuilder $builder) {}

    public function catalogue(): array
    {
        return $this->templates->catalogue();
    }

    public function offers(string $categoryKey, string $templateKey): bool
    {
        foreach ($this->catalogue() as $category) {
            if ($category['key'] === $categoryKey) {
                return in_array($templateKey, array_column($category['templates'], 'key'), true);
            }
        }

        return false;
    }

    public function apply(string $templateKey): void
    {
        $this->builder->apply($templateKey);
    }
}
