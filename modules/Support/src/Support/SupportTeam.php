<?php

declare(strict_types=1);

namespace Wurk\Support\Support;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Wurk\Identity\Contracts\PeopleDirectory;

/** Who can be given tickets: active WURK staff whose role answers support tickets (Sprint 16.3b). */
final readonly class SupportTeam
{
    public function __construct(private PeopleDirectory $people, private Gate $gate) {}

    /** @return list<array{id: string, name: string}> */
    public function members(): array
    {
        $provider = Auth::guard()->getProvider();
        $out = [];
        foreach ($this->people->staff() as $s) {
            $user = $s['active'] ? $provider->retrieveById($s['id']) : null;
            if ($user instanceof Authenticatable && $this->gate->forUser($user)->allows('support.manage')) {
                $out[] = ['id' => $s['id'], 'name' => $s['name']];
            }
        }

        return $out;
    }

    public function has(string $id): bool
    {
        return in_array($id, array_column($this->members(), 'id'), true);
    }
}
