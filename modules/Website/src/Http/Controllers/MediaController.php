<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Models\Site;
use Wurk\Website\Models\SiteAsset;
use Wurk\Website\Support\SiteAssets;

/** Owners: Website → Media — every photo, where it's used, its description, and storage. */
final class MediaController
{
    public function __construct(private readonly SiteAssets $assets) {}

    public function index(TenantContext $context, Entitlements $entitlements): Response
    {
        $usage = $this->usage();

        return Inertia::render('Website::Media', [
            'photos' => SiteAsset::query()->latest()->get()->map(fn (SiteAsset $a) => [
                ...$this->assets->photo($a, (string) $a->description), 'id' => $a->id, 'name' => $a->name, 'description' => $a->description,
                'ai' => $a->ai_generated, 'kb' => (int) round($a->bytes / 1024), 'width' => $a->width, 'height' => $a->height,
                'usedIn' => $usage[strtolower($a->id)] ?? [],
            ])->all(),
            'usedMb' => round($this->assets->usedBytes() / 1048576, 1),
            'limitMb' => (int) ($entitlements->limits($context->id())['storage_mb'] ?? 0),
            'base' => route('tenant.website.media', [], false),
        ]);
    }

    public function update(Request $request, string $asset, AuditLog $audit): RedirectResponse
    {
        $description = $request->validate(['description' => ['nullable', 'string', 'max:200']])['description'] ?? null;
        SiteAsset::query()->findOrFail($asset)->update(['description' => $description]);

        return back()->with('status', 'Description saved. It’s offered wherever you use this photo.');
    }

    public function destroy(string $asset, AuditLog $audit): RedirectResponse
    {
        $photo = SiteAsset::query()->findOrFail($asset);
        $usedIn = $this->usage()[strtolower($photo->id)] ?? [];
        if ($usedIn !== []) {
            throw ValidationException::withMessages(['photo' => 'This photo is still used on: '.implode(', ', $usedIn).'. Remove it there first.']);
        }
        $disk = Storage::disk((string) config('wurk.site_assets_disk'));
        foreach ($photo->variants as $path) {
            $disk->delete($path);
        }
        $photo->delete();
        $audit->record('website.photo_deleted', 'site_asset', $photo->id, ['name' => $photo->name]);

        return back()->with('status', 'Photo deleted.');
    }

    /**
     * Where each photo appears: page drafts, the live version of each page, and the logo/icon.
     *
     * @return array<string, list<string>> photo id (lower case) → place names
     */
    private function usage(): array
    {
        $found = [];
        $scan = function (string $text, string $place) use (&$found): void {
            if (preg_match_all('#/sites/[0-9A-Za-z]+/([0-9a-z]{26})-\d+\.webp#', $text, $m) > 0) {
                foreach (array_unique($m[1]) as $id) {
                    $found[$id][$place] = true;
                }
            }
        };
        foreach (Page::query()->get(['id', 'title', 'draft']) as $page) {
            $scan((string) $page->draft, $page->title);
            $scan((string) PageVersion::query()->where('page_id', $page->id)->latest('number')->value('content'), $page->title.' (live)');
        }
        $site = Site::query()->first();
        if ($site !== null) {
            $scan((string) json_encode([$site->branding['logo'] ?? null]), 'Logo');
            $scan((string) json_encode([$site->branding['favicon'] ?? null]), 'Browser icon');
        }

        return array_map(fn (array $places) => array_keys($places), $found);
    }
}
