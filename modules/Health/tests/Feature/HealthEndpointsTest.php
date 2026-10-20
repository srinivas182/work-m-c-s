<?php

declare(strict_types=1);

use Wurk\Health\Contracts\CheckResult;
use Wurk\Health\Contracts\HealthCheck;

it('reports liveness without touching dependencies', function () {
    $this->getJson('/health/live')
        ->assertOk()
        ->assertExactJson(['status' => 'ok'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('reports ready when every check passes', function () {
    $this->getJson('/health/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.ok', true)
        ->assertJsonPath('checks.cache.ok', true);
});

it('returns 503 when any check fails, without leaking details', function () {
    app()->bind('failing-check', fn () => new class implements HealthCheck
    {
        public function name(): string
        {
            return 'search';
        }

        public function run(): CheckResult
        {
            return CheckResult::failed('Search unreachable');
        }
    });
    app()->tag(['failing-check'], HealthCheck::TAG);

    $this->getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.search.ok', false)
        ->assertJsonPath('checks.search.message', 'Search unreachable')
        ->assertJsonPath('checks.database.ok', true);
});

it('does not start a session on health endpoints', function () {
    $this->get('/health/ready')->assertCookieMissing(config('session.cookie'));
});
