<?php

declare(strict_types=1);

namespace Wurk\Push\Support;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Platform\Contracts\PushNotifications;
use Wurk\Push\Jobs\SendPush;
use Wurk\Push\Models\PushSubscription;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Who gets a push (Sprint 26.2): active team members of the business with the permission (or named), who haven't
 * switched that kind off here, on every phone they've turned notifications on. Sent from the queue.
 */
final readonly class Notifier implements PushNotifications
{
    public function __construct(private TenantContext $context, private TeamDirectory $team, private Gate $gate) {}

    public function notify(string $kind, string $title, string $body, string $url, ?string $permission = null, array $also = [], ?string $except = null, ?string $branchId = null): void
    {
        if (! $this->context->has() || ! isset(self::KINDS[$kind])) {
            return;
        }
        $people = [];
        foreach ($this->team->roster() as $m) {
            if (! $m['active']) {
                continue;
            }
            $id = $m['identity_id'];
            if ($id === $except || ($branchId !== null && ! $m['all_branches'] && ! in_array($branchId, $m['branches'], true) && ! in_array($id, $also, true))) {
                continue;
            }
            if (in_array($id, $also, true) || $permission === null || $this->allows($id, $permission)) {
                $people[$id] = true;
            }
        }
        $wanted = array_values(array_filter(array_keys($people), fn (string $id) => ! in_array($kind, $this->off($id), true)));
        if ($wanted === []) {
            return;
        }
        $payload = (string) json_encode(['title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 180), 'url' => $url, 'tag' => $kind,
            'business' => $this->context->current()->displayName()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach (PushSubscription::query()->whereIn('identity_id', $wanted)->pluck('id') as $subscription) {
            SendPush::dispatch((string) $subscription, $payload);
        }
    }

    /** @return list<string> the kinds this person has switched off in the current business */
    public function off(string $identityId): array
    {
        $row = DB::connection($this->context->connectionName())->table('push_preferences')->where('tenant_id', $this->context->id())->where('identity_id', $identityId)->value('off');

        return array_values(array_filter((array) json_decode((string) ($row ?? '[]'), true), 'is_string'));
    }

    /** @param list<string> $off */
    public function saveOff(string $identityId, array $off): void
    {
        DB::connection($this->context->connectionName())->table('push_preferences')->updateOrInsert(['tenant_id' => $this->context->id(), 'identity_id' => $identityId],
            ['off' => json_encode(array_values(array_intersect(array_keys(self::KINDS), $off))), 'updated_at' => now(), 'created_at' => now()]);
    }

    private function allows(string $identityId, string $permission): bool
    {
        $user = Auth::getProvider()->retrieveById($identityId);

        return $user !== null && $this->gate->forUser($user)->allows($permission);
    }
}
