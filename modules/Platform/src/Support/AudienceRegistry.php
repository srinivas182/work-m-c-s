<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Wurk\Platform\Contracts\AudienceSegments;

final class AudienceRegistry implements AudienceSegments
{
    /** @var array<string, array{label: string, contacts: \Closure}> */
    private array $segments = [];

    public function register(string $key, string $label, \Closure $contacts): void
    {
        $this->segments[$key] = ['label' => $label, 'contacts' => $contacts];
    }

    public function available(): array
    {
        $out = [];
        foreach ($this->segments as $key => $s) {
            if ($this->contacts($key) !== null) {
                $out[$key] = $s['label'];
            }
        }

        return $out;
    }

    public function contacts(string $key): ?array
    {
        if (! isset($this->segments[$key])) {
            return null;
        }
        /** @var list<string>|null $ids */
        $ids = ($this->segments[$key]['contacts'])();

        return $ids;
    }
}
