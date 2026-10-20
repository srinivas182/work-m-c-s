<?php

declare(strict_types=1);

use Wurk\Health\Actions\RunHealthChecks;
use Wurk\Health\Contracts\CheckResult;
use Wurk\Health\Contracts\HealthCheck;

function fakeCheck(string $name, CheckResult $result): HealthCheck
{
    return new class($name, $result) implements HealthCheck
    {
        public function __construct(private string $checkName, private CheckResult $result) {}

        public function name(): string
        {
            return $this->checkName;
        }

        public function run(): CheckResult
        {
            return $this->result;
        }
    };
}

it('is ok only when all checks pass', function () {
    $report = (new RunHealthChecks([
        fakeCheck('a', CheckResult::ok()),
        fakeCheck('b', CheckResult::failed('down')),
    ]))();

    expect($report['ok'])->toBeFalse()
        ->and($report['checks']['a']['ok'])->toBeTrue()
        ->and($report['checks']['b'])->toMatchArray(['ok' => false, 'message' => 'down'])
        ->and($report['checks']['a']['duration_ms'])->toBeFloat();
});

it('is ok with no checks registered', function () {
    expect((new RunHealthChecks([]))()['ok'])->toBeTrue();
});
