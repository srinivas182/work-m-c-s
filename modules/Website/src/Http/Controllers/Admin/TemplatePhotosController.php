<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Website\Models\SiteTemplate;
use Wurk\Website\Models\TemplateAsset;
use Wurk\Website\Support\TemplatePhotoImporter;
use Wurk\Website\Support\TemplatePhotos;

/**
 * Admin → Templates → a design → Demo photos (Sprint 20.3): fill each photo slot with a licensed photo, by link or
 * upload, and record who took it and under which licence. New sites with the design get it, marked demo.
 */
final class TemplatePhotosController
{
    /**
     * The design's slots, each with what it should show, search words, and HQ's photo (or the stand-in).
     *
     * @return list<array<string, mixed>>
     */
    public static function slotsFor(SiteTemplate $model, TemplatePhotos $photos): array
    {
        $definition = (array) json_decode((string) $model->draft, true);
        $chosen = $photos->chosen($model);
        $disk = Storage::disk((string) config('wurk.site_assets_disk'));

        return array_map(function (array $slot) use ($chosen, $model, $disk) {
            $asset = $chosen[$slot['slot']] ?? null;
            $search = rawurlencode($slot['search']);

            return [...$slot,
                'photo' => $asset === null ? '/images/templates/'.$model->key.'/'.$slot['slot'].'-640.webp'
                    : $disk->url((string) collect($asset->variants)->sortKeys()->first()), // the smallest size
                'chosen' => $asset === null ? null : [
                    'photographer' => $asset->photographer, 'licence' => $asset->licence, 'source' => $asset->source_url, 'ai' => $asset->ai_generated,
                    'width' => $asset->width, 'height' => $asset->height,
                ],
                'find' => [
                    ['site' => 'Nappy', 'url' => 'https://nappy.co/search?q='.$search],
                    ['site' => 'Pexels', 'url' => 'https://www.pexels.com/search/'.$search.'/'],
                    ['site' => 'Unsplash', 'url' => 'https://unsplash.com/s/photos/'.str_replace('%20', '-', $search)],
                ],
            ];
        }, TemplatePhotos::slots($model->key, $definition));
    }

    public function store(Request $request, string $template, string $slot, TemplatePhotoImporter $importer): RedirectResponse
    {
        Gate::authorize('templates.manage');
        $model = SiteTemplate::query()->where('key', $template)->firstOrFail();
        $definition = (array) json_decode((string) $model->draft, true);
        $known = array_column(TemplatePhotos::slots($model->key, $definition), null, 'slot');
        abort_unless(isset($known[$slot]), 404);
        $data = $request->validate([
            'link' => ['nullable', 'required_without:photo', 'string', 'max:500'],
            'photo' => ['nullable', 'required_without:link', 'file'],
            'photographer' => ['nullable', 'string', 'max:120'],
            'licence' => ['required', Rule::in(TemplatePhotoImporter::LICENCES)],
        ], ['link.required_without' => 'Paste a link, or upload a photo.', 'licence.required' => 'Choose the licence the photo is used under.']);
        // WURK AI pictures never stand in for a business's people, work or premises (Sprint 7): decorative slots only.
        if ($data['licence'] === 'WURK AI' && ! $known[$slot]['decorative']) {
            throw ValidationException::withMessages(['licence' => 'AI images are only for decorative places (backgrounds, textures). This place shows real people or work.']);
        }
        $by = (string) $request->user()?->getAuthIdentifier();
        $credit = ['photographer' => $data['photographer'] ?? null, 'licence' => $data['licence']];
        $request->hasFile('photo')
            ? $importer->fromUpload($model, $slot, $request->file('photo'), $credit, $by)
            : $importer->fromLink($model, $slot, (string) $data['link'], $credit, $by);

        return back()->with('status', 'Demo photo saved. New sites with this design get it (marked demo).');
    }

    /** Back to the stand-in: removes HQ's photos for this slot (sites made already keep theirs). */
    public function destroy(string $template, string $slot, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('templates.manage');
        $model = SiteTemplate::query()->where('key', $template)->firstOrFail();
        $assets = TemplateAsset::query()->where('template_id', $model->id)->where('slot', $slot)->get();
        abort_if($assets->isEmpty(), 404);
        // The files stay: sites made with this design may still show them.
        TemplateAsset::query()->whereIn('id', $assets->pluck('id'))->update(['slot' => null]);
        $audit->record('website.template_photo_cleared', 'site_template', $model->id, ['slot' => $slot]);

        return back()->with('status', 'Demo photo removed from this place. New sites get the stand-in until you add another.');
    }
}
