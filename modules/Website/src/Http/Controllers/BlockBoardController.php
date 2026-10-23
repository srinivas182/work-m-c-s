<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Design board for the block library: sample businesses, not available in production. */
final class BlockBoardController
{
    public function __invoke(Request $request): Response
    {
        abort_if(app()->environment('production'), 404);

        return Inertia::render('Website::BlockBoard', ['business' => $request->query('business') === 'dental' ? 'dental' : 'salon']);
    }
}
