<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Support\ChatBot;

/** The chat the website loads (only when switched on): greeting, buttons, keyword answers, reply time. */
final class PublicChatController
{
    public function config(string $handle, TenantDirectory $tenants, TenantContext $context): JsonResponse
    {
        $tenant = $tenants->findByHandle($handle);
        abort_if($tenant === null || ! $tenant->isActive(), 404);

        return $context->run($tenant, function (): JsonResponse {
            $bot = app(ChatBot::class);
            abort_unless($bot->settings()['enabled'], 404);

            return (new JsonResponse($bot->publicConfig()))->setPublic()->setMaxAge(300); // answers change rarely
        });
    }
}
