<?php

declare(strict_types=1);

namespace Wurk\Help\Support;

/** The back-office screens an article can belong to — every Inertia page in the modules (e.g. "Reports::Overview"). */
final class Screens
{
    /** @return list<string> */
    public function all(): array
    {
        $out = [];
        foreach (glob(base_path('modules/*/resources/js/pages/{*,*/*}.tsx'), GLOB_BRACE) ?: [] as $file) {
            if (str_contains($file, '.test.')) {
                continue;
            }
            if (preg_match('#modules/([^/]+)/resources/js/pages/(.+)\.tsx$#', $file, $m) === 1) {
                $out[] = $m[1].'::'.$m[2];
            }
        }
        sort($out);

        return $out;
    }
}
