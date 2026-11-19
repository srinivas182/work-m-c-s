<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Domains;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Wurk\Tenancy\Contracts\CustomDomains;
use Wurk\Tenancy\Contracts\CustomHostnames;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Events\CustomDomainChanged;
use Wurk\Tenancy\Models\TenantDomain;

/**
 * Sprint 17.3. The rules for owners' own domains:
 * - A real domain the owner controls (not a wurk.co.za address, not one another business uses), at most
 *   `per_business` of them.
 * - Pending until it points at WURK (one CNAME) and Cloudflare has issued its certificate; let go after
 *   `pending_days` if it never does.
 * - The caller (Settings → Your domain) audits what the owner does; status changes found by the scheduled check are
 *   announced with CustomDomainChanged.
 * - Only an active domain can be the main address. If it lapses (stops pointing at WURK), the wurk.co.za address is
 *   the main one again and the owner is told — the site never goes dark.
 */
final readonly class CustomDomainManager implements CustomDomains
{
    public function __construct(private TenantContext $context, private CustomHostnames $hostnames) {}

    public function list(): array
    {
        return array_values(TenantDomain::query()->where('tenant_id', $this->context->id())->where('type', TenantDomain::TYPE_CUSTOM)->orderBy('created_at')->get()
            ->map(fn (TenantDomain $d) => ['id' => $d->id, 'host' => $d->host, 'status' => $d->status, 'ssl' => $d->ssl_status, 'primary' => $d->is_primary,
                'problem' => $d->problem, 'checked_at' => $d->checked_at?->toIso8601String(), 'added_at' => (string) $d->created_at?->toIso8601String()])->all());
    }

    public function add(string $host, string $by): string
    {
        $host = self::normalise($host);
        $root = strtolower((string) config('wurk.domains.root'));
        if ($host === null) {
            throw ValidationException::withMessages(['host' => __('Type your domain like this: www.yourbusiness.co.za')]);
        }
        if ($host === $root || str_ends_with($host, '.'.$root)) {
            throw ValidationException::withMessages(['host' => __('That’s a WURK address. Type a domain you own.')]);
        }
        $existing = TenantDomain::query()->where('host', $host)->first();
        if ($existing !== null) {
            throw ValidationException::withMessages(['host' => $existing->tenant_id === $this->context->id() ? __('You’ve added that domain already.')
                : __('Another business on WURK uses that domain. If it’s yours, ask us for help.')]);
        }
        $count = TenantDomain::query()->where('tenant_id', $this->context->id())->where('type', TenantDomain::TYPE_CUSTOM)->count();
        if ($count >= (int) config('wurk.custom_domains.per_business', 2)) {
            throw ValidationException::withMessages(['host' => __('You can connect :n domains. Remove one first.', ['n' => (int) config('wurk.custom_domains.per_business', 2)])]);
        }
        try {
            $h = $this->hostnames->create($host);
        } catch (CustomHostnameFailed $e) {
            throw ValidationException::withMessages(['host' => $e->getMessage()]);
        }
        TenantDomain::query()->create(['tenant_id' => $this->context->id(), 'host' => $host, 'type' => TenantDomain::TYPE_CUSTOM, 'is_primary' => false,
            'status' => TenantDomain::PENDING, 'provider_id' => $h['id'], 'ssl_status' => $h['ssl'], 'problem' => null, 'checked_at' => now()]);

        return $host;
    }

    public function check(string $domainId): string
    {
        return $this->refresh($this->find($domainId));
    }

    /** Asks the domain service about one domain and moves it on. Used by the scheduled check for every business. */
    public function refresh(TenantDomain $d): string
    {
        if ($d->provider_id === null) {
            return $d->status;
        }
        try {
            $h = $this->hostnames->get($d->provider_id);
        } catch (CustomHostnameFailed) {
            return $d->status; // try again next time
        }
        $live = $h !== null && $h['status'] === 'active' && $h['ssl'] === 'active';
        $before = $d->status;
        $status = match (true) {
            $live => TenantDomain::ACTIVE,
            $before === TenantDomain::PENDING => TenantDomain::PENDING,
            default => TenantDomain::LAPSED,
        };
        $problem = $live ? null : ($h === null ? __('This domain isn’t connected to WURK any more. Add it again to use it.') : self::explain($h));
        DB::transaction(function () use ($d, $status, $h, $problem, $live): void {
            $d->update(['status' => $status, 'ssl_status' => $h['ssl'] ?? null, 'problem' => $problem, 'checked_at' => now(),
                'verified_at' => $live ? ($d->verified_at ?? now()) : $d->verified_at, 'is_primary' => $status === TenantDomain::LAPSED ? false : $d->is_primary]);
            if ($status === TenantDomain::LAPSED) {
                $this->subdomainIsMain($d->tenant_id);
            }
        });
        if ($status !== $before && $status !== TenantDomain::PENDING) {
            event(new CustomDomainChanged($d->tenant_id, $d->host, $status));
        }

        return $status;
    }

    public function makePrimary(string $domainId, string $by): string
    {
        $d = $this->find($domainId);
        if ($d->status !== TenantDomain::ACTIVE) {
            throw ValidationException::withMessages(['domain' => __('This domain isn’t working yet. Once it is, you can make it your main address.')]);
        }
        DB::transaction(function () use ($d): void {
            TenantDomain::query()->where('tenant_id', $d->tenant_id)->where('id', '!=', $d->id)->where('is_primary', true)->get()->each->update(['is_primary' => false]);
            $d->update(['is_primary' => true]);
        });

        return $d->host;
    }

    public function remove(string $domainId, string $by): string
    {
        $d = $this->find($domainId);
        if ($d->provider_id !== null) {
            try {
                $this->hostnames->delete($d->provider_id);
            } catch (CustomHostnameFailed $e) {
                throw ValidationException::withMessages(['domain' => $e->getMessage()]);
            }
        }
        DB::transaction(function () use ($d): void {
            $wasPrimary = $d->is_primary;
            $d->delete();
            if ($wasPrimary) {
                $this->subdomainIsMain($d->tenant_id);
            }
        });

        return $d->host;
    }

    /** www.Glow-Salon.co.za/ → www.glow-salon.co.za; null when it isn't a domain. */
    public static function normalise(string $input): ?string
    {
        $host = strtolower(trim($input));
        $host = (string) preg_replace('#^[a-z]+://#', '', $host);
        $host = rtrim(explode('/', $host)[0], '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $host = (string) idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        }

        return strlen($host) <= 253 && preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $host) === 1 ? $host : null;
    }

    /** @param array{id: string, status: string, ssl: string, problems: list<string>} $h */
    private static function explain(array $h): string
    {
        if ($h['status'] === 'moved' || $h['status'] === 'deleted') {
            return __('Your domain doesn’t point at WURK any more. Put back the CNAME record shown below.');
        }
        if ($h['problems'] !== []) {
            return mb_substr(__('Not working yet: :why', ['why' => implode(' ', $h['problems'])]), 0, 300);
        }

        return __('Waiting for your domain to point at WURK. This usually takes a few minutes after you add the record, sometimes a few hours.');
    }

    private function subdomainIsMain(string $tenantId): void
    {
        TenantDomain::query()->where('tenant_id', $tenantId)->where('type', TenantDomain::TYPE_SUBDOMAIN)->get()->each->update(['is_primary' => true]);
    }

    private function find(string $id): TenantDomain
    {
        return TenantDomain::query()->where('tenant_id', $this->context->id())->where('type', TenantDomain::TYPE_CUSTOM)->whereKey($id)->firstOrFail();
    }
}
