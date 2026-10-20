<?php

declare(strict_types=1);

namespace Wurk\Platform\Navigation;

/**
 * One back-office navigation entry, as rendered by the app shell.
 */
final readonly class NavigationItem
{
    public function __construct(
        public string $label,
        public string $href,
        public string $icon,
        public string $section,
        public int $order,
        public bool $active,
    ) {}

    /**
     * @return array{label: string, href: string, icon: string, section: string, active: bool}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'href' => $this->href,
            'icon' => $this->icon,
            'section' => $this->section,
            'active' => $this->active,
        ];
    }
}
