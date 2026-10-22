<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wurk\Website\Exceptions\DraftConflict;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Support\Pages;

/** JSON for the visual editor (4.3): drafts, publishing and versions. */
final class DraftController
{
    public function __construct(private readonly Pages $pages) {}

    public function show(string $page): JsonResponse
    {
        $model = Page::query()->findOrFail($page);

        return new JsonResponse([
            'id' => $model->id, 'title' => $model->title, 'revision' => $model->draft_revision,
            'content' => json_decode($model->draft, true), 'published_version_id' => $model->published_version_id,
        ]);
    }

    public function save(Request $request, string $page): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'content' => ['required', 'array']]);

        try {
            $revision = $this->pages->saveDraft(Page::query()->findOrFail($page), $data['content'], (int) $data['revision'], (string) $request->user()?->getAuthIdentifier());
        } catch (DraftConflict $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'revision' => $e->currentRevision], 409);
        }

        return new JsonResponse(['revision' => $revision]);
    }

    public function publish(Request $request, string $page): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;
        $version = $this->pages->publish(Page::query()->findOrFail($page), $note, (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['version' => $version->number, 'id' => $version->id]);
    }

    public function unpublish(string $page): JsonResponse
    {
        $this->pages->unpublish(Page::query()->findOrFail($page));

        return new JsonResponse(['published' => false]);
    }

    public function versions(string $page): JsonResponse
    {
        $model = Page::query()->findOrFail($page);

        return new JsonResponse(PageVersion::query()->where('page_id', $model->id)->orderByDesc('number')->get()
            ->map(fn (PageVersion $v) => ['id' => $v->id, 'number' => $v->number, 'note' => $v->note, 'published_at' => $v->created_at->toIso8601String(), 'live' => $v->id === $model->published_version_id])->all());
    }

    public function restore(Request $request, string $page, string $version): JsonResponse
    {
        $model = Page::query()->findOrFail($page);
        $revision = $this->pages->restoreVersion($model, PageVersion::query()->findOrFail($version), (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['revision' => $revision]);
    }
}
