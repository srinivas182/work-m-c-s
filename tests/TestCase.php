<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests assert server responses; they must not depend on a
        // compiled frontend build being present (CI runs them in parallel jobs).
        $this->withoutVite();
    }
}
