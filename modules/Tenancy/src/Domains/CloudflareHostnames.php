<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Domains;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Wurk\Tenancy\Contracts\CustomHostnames;

/**
 * Cloudflare for SaaS custom hostnames (Sprint 17.3, decision E). Each owner's domain is a custom hostname on WURK's
 * zone: Cloudflare issues and renews its certificate (HTTP validation once the domain points at WURK) and sends its
 * visitors to WURK's origin with the visitor's Host header — so WURK knows which business — while connecting to the
 * origin as `origin.<root>`, whose certificate the load balancer has (Full (strict) end to end).
 */
final readonly class CloudflareHostnames implements CustomHostnames
{
    private const API = 'https://api.cloudflare.com/client/v4';

    public function configured(): bool
    {
        return (string) config('wurk.custom_domains.cloudflare_token') !== '' && (string) config('wurk.custom_domains.cloudflare_zone') !== '';
    }

    public function create(string $host): array
    {
        $r = $this->send(fn (PendingRequest $http) => $http->post($this->url(), [
            'hostname' => $host,
            'ssl' => ['method' => 'http', 'type' => 'dv', 'settings' => ['min_tls_version' => '1.2']],
            'custom_origin_server' => (string) config('wurk.custom_domains.origin'),
        ]));

        return self::shape((array) $r?->json('result'));
    }

    public function get(string $id): ?array
    {
        $r = $this->send(fn (PendingRequest $http) => $http->get($this->url($id)), allowMissing: true);

        return $r === null ? null : self::shape((array) $r->json('result'));
    }

    public function delete(string $id): void
    {
        $this->send(fn (PendingRequest $http) => $http->delete($this->url($id)), allowMissing: true);
    }

    /**
     * @param  array<string, mixed>  $h
     * @return array{id: string, status: string, ssl: string, problems: list<string>}
     */
    public static function shape(array $h): array
    {
        $problems = [];
        foreach ([(array) ($h['verification_errors'] ?? []), (array) ($h['ssl']['validation_errors'] ?? [])] as $list) {
            foreach ($list as $e) {
                $message = is_array($e) ? (string) ($e['message'] ?? '') : (string) $e;
                if ($message !== '') {
                    $problems[] = mb_substr($message, 0, 200);
                }
            }
        }

        return ['id' => (string) ($h['id'] ?? ''), 'status' => (string) ($h['status'] ?? 'pending'), 'ssl' => (string) ($h['ssl']['status'] ?? 'pending'),
            'problems' => array_values(array_unique($problems))];
    }

    private function url(?string $id = null): string
    {
        return self::API.'/zones/'.config('wurk.custom_domains.cloudflare_zone').'/custom_hostnames'.($id !== null ? '/'.rawurlencode($id) : '');
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(callable $call, bool $allowMissing = false): ?Response
    {
        if (! $this->configured()) {
            throw new CustomHostnameFailed(__('WURK’s domain service isn’t set up yet. Please try again later.'));
        }
        try {
            $r = $call(Http::withToken((string) config('wurk.custom_domains.cloudflare_token'))->acceptJson()->timeout(15)->retry(2, 500, throw: false));
        } catch (ConnectionException) {
            throw new CustomHostnameFailed(__('The domain service didn’t answer. Please try again in a minute.'));
        }
        if ($allowMissing && $r->status() === 404) {
            return null;
        }
        if (! $r->successful() || $r->json('success') === false) {
            report(new \RuntimeException('Cloudflare custom hostnames: HTTP '.$r->status().' '.mb_substr((string) $r->json('errors.0.message'), 0, 200)));
            throw new CustomHostnameFailed(__('The domain service refused that domain. Check it’s spelled right, or ask us for help.'));
        }

        return $r;
    }
}
