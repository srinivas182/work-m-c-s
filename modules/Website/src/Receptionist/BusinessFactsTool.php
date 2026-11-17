<?php

declare(strict_types=1);

namespace Wurk\Website\Receptionist;

use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Website\Support\SiteFacts;

/** The business's public facts, from its published website: what it does, contact details, opening hours, pages. */
final readonly class BusinessFactsTool implements ReceptionistTool
{
    public function __construct(private SiteFacts $facts) {}

    public function name(): string
    {
        return 'business_facts';
    }

    public function description(): string
    {
        return 'The business’s own facts from its website: what it does, address, phone, WhatsApp, email, opening hours, and its pages. Use it before answering any question about the business.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function capability(): string
    {
        return 'answer';
    }

    public function entitlement(): ?string
    {
        return null;
    }

    public function run(array $arguments, array $context): array
    {
        $f = $this->facts->collect();

        return [
            'name' => $f['name'], 'about' => $f['description'], 'contact' => $f['contact'],
            'opening_hours' => $f['hours'] ?? 'not published — say the business will confirm',
            'services_on_website' => $f['services'],
            'pages' => array_map(fn (array $p) => ['title' => $p['title'], 'path' => $p['path'], 'about' => $p['description']], $f['pages']),
        ];
    }
}
