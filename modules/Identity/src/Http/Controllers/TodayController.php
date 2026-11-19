<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Platform\Contracts\TodayList;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Today (Sprint 26.2): where the WURK app opens — what this person has to do today in this business, with the
 * buttons to do it. Each module adds its part (my next bookings, chats waiting, orders to pack).
 */
final class TodayController
{
    public function __invoke(Request $request, TenantContext $context, TodayList $today): Response
    {
        $tz = $context->current()->timezone();

        return Inertia::render('Identity::Today', [
            'date' => now($tz)->format('l j F'),
            'sections' => $today->for((string) $request->user()?->getAuthIdentifier()),
        ]);
    }
}
