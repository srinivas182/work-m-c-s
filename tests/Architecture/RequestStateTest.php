<?php

declare(strict_types=1);

/*
| Inertia::share() stores data in a singleton. Under Octane, workers serve many
| requests, so shared data from one business's request can appear in the next
| request — another business's page. Attach per-request data to the request
| (e.g. $request->attributes) and read it in HandleInertiaRequests::share().
*/

it('never shares request data through Inertia::share() in modules', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/modules', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $info) {
        $file = $info->getPathname();
        if ($info->getExtension() !== 'php' || ! str_contains($file, '/src/')) {
            continue;
        }
        foreach (file($file) ?: [] as $n => $line) {
            if (str_contains($line, 'Inertia::share(') && ! str_contains(ltrim($line), '//')) {
                $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', $file).':'.($n + 1);
            }
        }
    }

    expect($offenders)->toBe([])
        ->and(iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/modules/Billing/src/Http/Middleware', FilesystemIterator::SKIP_DOTS))))->toBeGreaterThan(0);
});
