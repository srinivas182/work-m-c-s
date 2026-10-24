<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Ai\Contracts\AiActionRefused;
use Wurk\Ai\Contracts\AiActions;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Ai\SiteGenerator;
use Wurk\Website\Support\Pages;

/** Owners: Website → Write with AI. Every run is confirmed with its price first. */
final class AiWriterController
{
    public function show(Pages $pages, TenantContext $context): Response
    {
        $pages->ensureSite();

        return Inertia::render('Website::AiWriter', ['business' => $context->current()->displayName(), 'base' => route('tenant.website.ai', [], false)]);
    }

    public function quote(Request $request, AiActions $actions): JsonResponse
    {
        $action = (string) $request->validate(['action' => ['required', 'in:'.implode(',', array_keys(AiActions::ACTIONS))]])['action'];

        return new JsonResponse($actions->quote($action));
    }

    public function generate(Request $request, SiteGenerator $generator): RedirectResponse
    {
        $brief = $request->validate([
            'confirmation' => ['required', 'string', 'max:64'],
            'business' => ['required', 'string', 'max:80'],
            'what' => ['required', 'string', 'max:300'],
            'area' => ['nullable', 'string', 'max:80'],
            'tone' => ['required', 'in:warm,professional,playful,luxurious'],
            'services' => ['array', 'max:20'],
            'services.*.name' => ['required', 'string', 'max:60'],
            'services.*.price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);
        try {
            $count = $generator->generate([
                'business' => $brief['business'], 'what' => $brief['what'], 'area' => $brief['area'] ?? '', 'tone' => $brief['tone'],
                'services' => array_values($brief['services'] ?? []),
            ], $brief['confirmation'], (string) $request->user()?->getAuthIdentifier());
        } catch (AiActionRefused|AiUnavailable $e) {
            throw ValidationException::withMessages(['ai' => $e->getMessage()]);
        }

        return redirect()->route('tenant.website.pages')->with('status', "WURK AI wrote {$count} page".($count === 1 ? '' : 's').'. Review them in the editor, then publish when you’re happy.');
    }
}
