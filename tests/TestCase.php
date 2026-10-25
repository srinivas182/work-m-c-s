<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Process;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Publishing renders pages with Node (bootstrap/site-render), which backend CI doesn't build.
        // Simulate it by default; rendering tests set their own result (same '*' key replaces this),
        // and the real renderer is covered end to end by the browser tests.
        Process::fake(['*' => Process::result(output: (string) json_encode(['html' => '<main class="wurk-site"></main>', 'fonts' => []]))]);

        // Feature tests assert server responses; they must not depend on a
        // compiled frontend build being present (CI runs them in parallel jobs).
        $this->withoutVite();
    }
}
