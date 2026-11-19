<?php

declare(strict_types=1);

/*
 * Sprint 17.2: steps a release runs after migrating, once per environment (`php artisan release:tasks`).
 *
 * - `every`: run on every deploy.
 * - `once`: keyed by the version that introduced them. They are the commands from docs/deployment/docker.md's
 *   version-specific steps, and each must be safe to run again. Steps that need a person (adding agents, evaluating the
 *   receptionist with real keys, importing photos) stay in docker.md.
 */
return [
    'every' => [
        'permissions:sync',
    ],
    'once' => [
        '0.14.0' => ['crm:link-existing'],
        '0.15.0' => ['portal:index-memberships'],
        '0.16.0' => ['website:install-templates'],
        '0.17.0' => ['credits:rollup-usage --since=2026-01-01', 'plans:snapshot-mrr'],
        '0.18.0' => ['success:score'],
        '0.21.0' => ['website:install-templates'],
    ],
];
