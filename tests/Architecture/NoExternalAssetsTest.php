<?php

declare(strict_types=1);

/*
| Nothing WURK serves may make the visitor's browser call a third-party host
| (POPIA; and it breaks wherever that host is blocked). Puck's default stylesheet
| imports Inter from rsms.me — the no-external variant must be used instead.
*/

it('never imports stylesheets that load fonts from other hosts', function () {
    $root = dirname(__DIR__, 2);
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/modules', FilesystemIterator::SKIP_DOTS));
    foreach ([...iterator_to_array($files), ...iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources', FilesystemIterator::SKIP_DOTS)))] as $info) {
        if (! in_array($info->getExtension(), ['ts', 'tsx', 'css'], true)) {
            continue;
        }
        $text = (string) file_get_contents($info->getPathname());
        if (str_contains($text, '@puckeditor/core/puck.css') || preg_match('#@import\s+(url\()?["\']?https?://#i', $text) || str_contains($text, 'fonts.googleapis.com')) {
            $offenders[] = str_replace($root.'/', '', $info->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
