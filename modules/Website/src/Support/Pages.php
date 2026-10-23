<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Exceptions\DraftConflict;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Models\Site;

/** Pages of the current business's website: drafts, publishing, versions. */
final readonly class Pages
{
    public const RESTORE_DAYS = 30;

    public function __construct(private TenantContext $context, private AuditLog $audit, private Branding $branding) {}

    /** Every business gets a site with a home page the first time it's needed. */
    public function ensureSite(): Site
    {
        $site = Site::query()->find($this->context->id());
        if ($site !== null) {
            return $site;
        }

        return DB::connection($this->context->connectionName())->transaction(function (): Site {
            $site = Site::query()->create(['tenant_id' => $this->context->id(), 'branding' => $this->branding->defaults(), 'menus' => ['header' => [], 'footer' => [], 'mobile' => []], 'settings' => []]);
            Page::query()->create(['title' => 'Home', 'slug' => 'home', 'position' => 0, 'is_home' => true, 'draft' => json_encode(ContentDocument::starter(['Header', 'Hero', 'Footer']))]);

            return $site;
        });
    }

    public function create(string $title, ?string $slug, ?string $by): Page
    {
        $this->ensureSite();
        $slug = $this->checkSlug($slug ?? $title, null);

        return Page::query()->create([
            'title' => $title, 'slug' => $slug, 'position' => (int) Page::query()->max('position') + 1,
            'draft' => json_encode(ContentDocument::starter(['Header', 'Text', 'Footer'])), 'updated_by' => $by,
        ]);
    }

    /** @param  array{title: string, slug: string, seo_title?: string|null, seo_description?: string|null}  $data */
    public function update(Page $page, array $data): Page
    {
        $page->update([
            'title' => $data['title'],
            'slug' => $page->is_home ? $page->slug : $this->checkSlug($data['slug'], $page->id),
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
        ]);

        return $page;
    }

    /** @param  list<string>  $ids  every page, in the new order */
    public function reorder(array $ids): void
    {
        $current = Page::query()->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        $given = $ids;
        sort($given);
        if ($given !== $current) {
            throw ValidationException::withMessages(['order' => 'The page list changed; refresh and try again.']);
        }
        foreach ($ids as $position => $id) {
            Page::query()->whereKey($id)->update(['position' => $position]);
        }
    }

    public function setHome(Page $page): void
    {
        DB::connection($this->context->connectionName())->transaction(function () use ($page): void {
            Page::query()->where('is_home', true)->update(['is_home' => false]);
            $page->update(['is_home' => true]);
        });
        $this->audit->record('website.home_changed', 'page', $page->id, ['title' => $page->title]);
    }

    public function delete(Page $page): void
    {
        if ($page->is_home) {
            throw ValidationException::withMessages(['page' => 'Choose another home page before deleting this one.']);
        }
        $page->delete();
        $this->audit->record('website.page_deleted', 'page', $page->id, ['title' => $page->title, 'restorable_until' => now()->addDays(self::RESTORE_DAYS)->toDateString()]);
    }

    public function restoreDeleted(string $id): Page
    {
        $page = Page::onlyTrashed()->whereKey($id)->where('deleted_at', '>', now()->subDays(self::RESTORE_DAYS))->firstOrFail();
        $slug = $page->slug;
        for ($i = 2; Page::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $page->slug.'-'.$i; // its old address was reused meanwhile
        }
        $page->restore();
        $page->update(['slug' => $slug, 'position' => (int) Page::query()->max('position') + 1]);
        $this->audit->record('website.page_restored', 'page', $page->id, ['title' => $page->title]);

        return $page;
    }

    /**
     * Saves the draft only if nobody else saved since $expectedRevision.
     *
     * @return int the new revision
     */
    public function saveDraft(Page $page, mixed $document, int $expectedRevision, ?string $by): int
    {
        $clean = ContentDocument::validate($document);

        return DB::connection($this->context->connectionName())->transaction(function () use ($page, $clean, $expectedRevision, $by): int {
            $locked = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();
            if ($locked->draft_revision !== $expectedRevision) {
                throw new DraftConflict($locked->draft_revision, $locked->updated_by);
            }
            $locked->update(['draft' => json_encode($clean), 'draft_revision' => $locked->draft_revision + 1, 'updated_by' => $by]);

            return $locked->draft_revision;
        });
    }

    public function publish(Page $page, ?string $note, ?string $by): PageVersion
    {
        $version = DB::connection($this->context->connectionName())->transaction(function () use ($page, $note, $by): PageVersion {
            $locked = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();
            $version = PageVersion::query()->create([
                'page_id' => $locked->id,
                'number' => (int) PageVersion::query()->where('page_id', $locked->id)->max('number') + 1,
                'title' => $locked->title, 'content' => $locked->draft, 'note' => $note, 'published_by' => $by,
            ]);
            $locked->update(['published_version_id' => $version->id]);

            return $version;
        });
        $this->audit->record('website.page_published', 'page', $page->id, ['title' => $page->title, 'version' => $version->number, 'note' => $note]);

        return $version;
    }

    public function unpublish(Page $page): void
    {
        $page->update(['published_version_id' => null]);
        $this->audit->record('website.page_unpublished', 'page', $page->id, ['title' => $page->title]);
    }

    /** Makes an old version the draft again (publishing it is a separate, deliberate step). */
    public function restoreVersion(Page $page, PageVersion $version, ?string $by): int
    {
        abort_unless($version->page_id === $page->id, 404);
        $revision = DB::connection($this->context->connectionName())->transaction(function () use ($page, $version, $by): int {
            $locked = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();
            $locked->update(['draft' => $version->content, 'draft_revision' => $locked->draft_revision + 1, 'updated_by' => $by]);

            return $locked->draft_revision;
        });
        $this->audit->record('website.version_restored', 'page', $page->id, ['version' => $version->number]);

        return $revision;
    }

    private function checkSlug(string $wanted, ?string $exceptId): string
    {
        $slug = Str::slug($wanted);
        if ($slug === '' || strlen($slug) > 80) {
            throw ValidationException::withMessages(['slug' => 'Use letters, numbers and dashes (up to 80).']);
        }
        if (in_array($slug, (array) config('website.reserved_slugs'), true)) {
            throw ValidationException::withMessages(['slug' => 'That web address is reserved. Choose another.']);
        }
        if (Page::query()->where('slug', $slug)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            throw ValidationException::withMessages(['slug' => 'Another page already uses that address.']);
        }

        return $slug;
    }
}
