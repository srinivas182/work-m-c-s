<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Website\Support\ShareKit;

/** Owners: Website → Share (23.3). Links tagged by where they're shared, WhatsApp Status images, posters and table cards. */
final class ShareKitController
{
    public function __construct(private ShareKit $kit) {}

    public function show(): Response
    {
        return Inertia::render('Website::ShareKit', [
            'items' => array_map(fn (array $i) => [...$i, 'links' => array_combine(ShareKit::SOURCES, array_map(fn (string $s) => ShareKit::tagged($i['url'], $s), ShareKit::SOURCES))], $this->kit->items()),
            'posters' => array_map(fn (string $k) => ['kind' => $k, 'label' => ShareKit::POSTERS[$k]], $this->kit->posterKinds()),
            'sizes' => [['key' => 'a4', 'label' => 'A4 poster'], ['key' => 'a5', 'label' => 'A5 poster'], ['key' => 'tent', 'label' => 'Table card (fold in half)']],
        ]);
    }

    /** The WhatsApp Status image for one item (1080 × 1920 PNG). */
    public function status(string $key): SymfonyResponse
    {
        $item = $this->kit->item($key) ?? abort(404);

        return response($this->kit->statusImage($item), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename="'.Str::slug($item['title']).'-status.png"', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function poster(Request $request, AuditLog $audit): SymfonyResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(ShareKit::POSTERS))], 'size' => ['required', Rule::in(array_keys(ShareKit::SIZES))]]);
        $pdf = $this->kit->poster($data['kind'], $data['size']);
        $audit->record('website.poster_made', 'site', null, $data);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'attachment; filename="'.$data['kind'].'-'.$data['size'].'.pdf"']);
    }
}
