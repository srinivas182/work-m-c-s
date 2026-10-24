<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Website\Exceptions\DraftConflict;
use Wurk\Website\Models\SiteCategory;
use Wurk\Website\Models\SiteTemplate;
use Wurk\Website\Models\SiteTemplateVersion;
use Wurk\Website\Models\TemplateAsset;
use Wurk\Website\Support\SiteAssets;
use Wurk\Website\Support\TemplateDefinition;
use Wurk\Website\Support\Templates;

/** WURK staff: categories and templates (spec §3, 6.1). */
final class TemplatesController
{
    public function __construct(private readonly Templates $templates) {}

    public function index(): Response
    {
        Gate::authorize('templates.manage');

        return Inertia::render('Website::Admin/Templates', [
            'categories' => SiteCategory::query()->orderBy('position')->get()->map(fn (SiteCategory $c) => [
                'id' => $c->id, 'key' => $c->key, 'name' => $c->name, 'description' => $c->description, 'active' => $c->active,
                'templates' => SiteTemplate::query()->where('category_id', $c->id)->orderBy('position')->get()
                    ->map(fn (SiteTemplate $t) => ['key' => $t->key, 'name' => $t->name, 'status' => $t->status, 'version' => $t->published_version])->all(),
            ])->all(),
            'allTemplates' => SiteTemplate::query()->orderBy('name')->get(['key', 'name'])->toArray(),
        ]);
    }

    public function updateCategory(Request $request, string $category): RedirectResponse
    {
        Gate::authorize('templates.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'description' => ['nullable', 'string', 'max:200'], 'active' => ['required', 'boolean']]);
        SiteCategory::query()->where('key', $category)->firstOrFail()->update($data);

        return back()->with('status', 'Category saved.');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('templates.manage');
        $data = $request->validate([
            'category' => ['required', 'exists:site_categories,key'],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:240'],
            'copy_from' => ['nullable', 'exists:site_templates,key'],
        ]);
        $key = Str::slug($data['name']);
        $template = $this->templates->create(SiteCategory::query()->where('key', $data['category'])->firstOrFail(), $key, $data['name'], $data['description'] ?? null, $data['copy_from'] ?? null, $this->by($request));

        return redirect()->route('admin.templates.show', ['template' => $template->key])->with('status', 'Template created.');
    }

    public function show(string $template): Response
    {
        Gate::authorize('templates.manage');
        $model = SiteTemplate::query()->with('category')->where('key', $template)->firstOrFail();
        $draft = (array) json_decode($model->draft, true);

        return Inertia::render('Website::Admin/Template', [
            'template' => ['key' => $model->key, 'name' => $model->name, 'description' => $model->description, 'status' => $model->status, 'version' => $model->published_version, 'category' => $model->category?->name, 'revision' => $model->draft_revision],
            'pages' => array_map(fn (array $p) => ['key' => $p['key'], 'title' => $p['title'], 'slug' => $p['slug'], 'is_home' => $p['is_home'], 'sections' => count($p['content']['blocks'] ?? [])], (array) ($draft['pages'] ?? [])),
            'menus' => $draft['menus'] ?? ['header' => [], 'footer' => []],
            'branding' => $draft['branding'] ?? [],
            'versions' => SiteTemplateVersion::query()->where('template_id', $model->id)->orderByDesc('number')->get(['id', 'number', 'note', 'created_at'])->toArray(),
            'fontPairings' => config('website.font_pairings'),
        ]);
    }

    /** Details, page list, menus and branding — the whole draft definition at once. */
    public function update(Request $request, string $template): RedirectResponse
    {
        Gate::authorize('templates.manage');
        $model = SiteTemplate::query()->where('key', $template)->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:240'],
            'revision' => ['required', 'integer'], 'pages' => ['required', 'array'], 'menus' => ['required', 'array'], 'branding' => ['required', 'array'],
        ]);
        $current = (array) json_decode($model->draft, true);
        $contents = collect((array) $current['pages'])->keyBy('key');
        // Page content is edited in the visual editor; here only the list, titles and addresses change.
        $pages = array_map(fn (array $p) => [...$p, 'content' => $contents->get($p['key'])['content'] ?? ['schema' => 1, 'blocks' => []]], array_values($data['pages']));
        // Definition first: if it is refused (conflict or invalid), the name must not change on its own.
        $this->save($model, ['pages' => $pages, 'menus' => $data['menus'], 'branding' => $data['branding']], (int) $data['revision']);
        $model->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);

        return back()->with('status', 'Template saved.');
    }

    public function publish(Request $request, string $template): RedirectResponse|JsonResponse
    {
        Gate::authorize('templates.manage');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;
        $version = $this->templates->publish(SiteTemplate::query()->where('key', $template)->firstOrFail(), $note, $this->by($request));

        return $request->wantsJson() ? new JsonResponse(['version' => $version->number, 'id' => $version->id]) : back()->with('status', "Version {$version->number} published — new businesses get it now.");
    }

    public function retire(Request $request, string $template): RedirectResponse
    {
        Gate::authorize('templates.manage');
        $this->templates->retire(SiteTemplate::query()->where('key', $template)->firstOrFail(), $this->by($request));

        return back()->with('status', 'Template retired. Businesses already using it keep their sites.');
    }

    // ── The visual editor, editing one page of the template draft ──────────────

    public function editPage(string $template, string $page): Response
    {
        Gate::authorize('templates.manage');
        $model = SiteTemplate::query()->where('key', $template)->firstOrFail();
        $definition = (array) json_decode($model->draft, true);
        $pageDef = collect((array) $definition['pages'])->firstWhere('key', $page) ?? abort(404);
        $base = route('admin.templates.show', ['template' => $model->key], false);

        return Inertia::render('Website::Editor', [
            'page' => ['id' => $pageDef['key'], 'title' => $model->name.' — '.$pageDef['title'], 'slug' => $pageDef['slug'], 'is_home' => $pageDef['is_home']],
            'revision' => $model->draft_revision,
            'content' => $pageDef['content'],
            'published' => $model->published_version !== null,
            'canPublish' => true,
            'businessName' => 'Your business',
            'branding' => [...(array) $definition['branding'], 'logo' => null, 'favicon' => null],
            'menus' => ['header' => array_map(fn ($i) => ['label' => $i['label'], 'href' => '#'], (array) ($definition['menus']['header'] ?? [])), 'footer' => array_map(fn ($i) => ['label' => $i['label'], 'href' => '#'], (array) ($definition['menus']['footer'] ?? []))],
            'urls' => ['pages' => $base, 'draft' => $base.'/pages/'.$pageDef['key'].'/draft', 'publish' => $base.'/publish', 'versions' => $base.'/versions', 'photos' => $base.'/photos'],
        ]);
    }

    public function saveDraft(Request $request, string $template, string $page): JsonResponse
    {
        Gate::authorize('templates.manage');
        $data = $request->validate(['revision' => ['required', 'integer'], 'content' => ['required', 'array']]);
        $model = SiteTemplate::query()->where('key', $template)->firstOrFail();
        $definition = (array) json_decode($model->draft, true);
        $found = false;
        $definition['pages'] = array_map(function (array $p) use ($page, $data, &$found) {
            if ($p['key'] !== $page) {
                return $p;
            }
            $found = true;

            return [...$p, 'content' => $data['content']];
        }, (array) $definition['pages']);
        abort_unless($found, 404);

        try {
            return new JsonResponse(['revision' => $this->templates->saveDraft($model, $definition, (int) $data['revision'])]);
        } catch (DraftConflict $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'revision' => $e->currentRevision], 409);
        }
    }

    public function versions(string $template): JsonResponse
    {
        Gate::authorize('templates.manage');
        $model = SiteTemplate::query()->where('key', $template)->firstOrFail();

        return new JsonResponse(SiteTemplateVersion::query()->where('template_id', $model->id)->orderByDesc('number')->get()
            ->map(fn (SiteTemplateVersion $v) => ['id' => $v->id, 'number' => $v->number, 'note' => $v->note, 'published_at' => $v->created_at?->toIso8601String(), 'live' => $v->number === $model->published_version])->all());
    }

    /** Copies a published version back into the draft (publishing it again is a separate step). */
    public function restoreVersion(string $template, string $version): JsonResponse
    {
        Gate::authorize('templates.manage');
        $model = SiteTemplate::query()->where('key', $template)->firstOrFail();
        $v = SiteTemplateVersion::query()->where('template_id', $model->id)->findOrFail($version);

        return new JsonResponse(['revision' => $this->templates->saveDraft($model, json_decode($v->definition, true), $model->draft_revision)]);
    }

    /** Staff preview of the draft — what publishing would give businesses. */
    public function preview(Request $request, string $template): Response
    {
        Gate::authorize('templates.manage');
        $model = SiteTemplate::query()->with('category')->where('key', $template)->firstOrFail();
        $definition = TemplateDefinition::validate(json_decode($model->draft, true));
        $pageKey = (string) $request->query('page', '');
        $pages = collect($definition['pages']);
        $page = ($pageKey === '' ? $pages->firstWhere('is_home', true) : $pages->firstWhere('key', $pageKey)) ?? abort(404);
        $base = route('admin.templates.preview', ['template' => $model->key], false);
        $href = fn (array $i) => $i['type'] === 'page' ? $base.'?page='.$i['page'] : $i['href'];

        return Inertia::render('Website::TemplatePreview', [
            'template' => ['key' => $model->key, 'name' => $model->name.' (draft)', 'category' => $model->category?->name],
            'page' => ['key' => $page['key'], 'title' => $page['title']],
            'pages' => $pages->map(fn ($p) => ['key' => $p['key'], 'title' => $p['title']])->values()->all(),
            'content' => $page['content'],
            'branding' => [...$definition['branding'], 'logo' => null, 'favicon' => null],
            'menus' => ['header' => array_map(fn ($i) => ['label' => $i['label'], 'href' => $href($i)], $definition['menus']['header']), 'footer' => array_map(fn ($i) => ['label' => $i['label'], 'href' => $href($i)], $definition['menus']['footer'])],
            'previewBase' => $base,
        ]);
    }

    // ── Template photos: the same safe pipeline as business photos, stored per template ──

    public function photos(string $template): JsonResponse
    {
        Gate::authorize('templates.manage');
        $model = SiteTemplate::query()->where('key', $template)->firstOrFail();
        $photos = TemplateAsset::query()->where('template_id', $model->id)->latest('created_at')->get()
            ->map(fn (TemplateAsset $a) => [...SiteAssets::photoFromVariants($a->variants, $a->width, $a->height), 'name' => $a->name])->all();

        // Templates have no storage quota (limit 0): the picker hides the usage line.
        return new JsonResponse(['photos' => $photos, 'used_mb' => 0, 'limit_mb' => 0]);
    }

    public function uploadPhoto(Request $request, string $template, SiteAssets $assets): JsonResponse
    {
        Gate::authorize('templates.manage');
        $model = SiteTemplate::query()->where('key', $template)->firstOrFail();
        $request->validate(['photo' => ['required', 'file']]);
        $id = (string) Str::ulid();
        $result = $assets->process($request->file('photo'), 'templates/'.$model->key, $id);
        TemplateAsset::query()->create([
            'id' => $id, 'template_id' => $model->id, 'name' => mb_substr((string) $request->file('photo')?->getClientOriginalName(), 0, 160) ?: 'photo',
            'width' => $result['width'], 'height' => $result['height'], 'bytes' => $result['bytes'], 'variants' => $result['variants'], 'created_at' => now(),
        ]);

        return new JsonResponse(SiteAssets::photoFromVariants($result['variants'], $result['width'], $result['height'], (string) $request->input('alt', '')), 201);
    }

    /** @param  array<string, mixed>  $definition */
    private function save(SiteTemplate $model, array $definition, int $revision): void
    {
        try {
            $this->templates->saveDraft($model, $definition, $revision);
        } catch (DraftConflict) {
            throw ValidationException::withMessages(['revision' => 'Someone else saved this template meanwhile. Reload to see their changes.']);
        }
    }

    private function by(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
