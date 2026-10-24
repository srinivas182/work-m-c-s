<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Website\Exceptions\DraftConflict;
use Wurk\Website\Models\SiteCategory;
use Wurk\Website\Models\SiteTemplate;
use Wurk\Website\Models\SiteTemplateVersion;

/** WURK staff manage templates: drafts, immutable published versions, retiring. */
final readonly class Templates
{
    public function __construct(private AuditLog $audit) {}

    public function create(SiteCategory $category, string $key, string $name, ?string $description, ?string $copyFrom, ?string $by): SiteTemplate
    {
        if (SiteTemplate::query()->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['key' => 'Another template already uses that key.']);
        }
        $draft = $copyFrom !== null
            ? SiteTemplate::query()->where('key', $copyFrom)->firstOrFail()->draft
            : (string) json_encode(TemplateDefinition::blank());

        $template = SiteTemplate::query()->create([
            'category_id' => $category->id, 'key' => $key, 'name' => $name, 'description' => $description,
            'position' => (int) SiteTemplate::query()->where('category_id', $category->id)->max('position') + 1, 'draft' => $draft,
        ]);
        $this->audit->record('templates.created', 'site_template', $template->id, ['key' => $key, 'copied_from' => $copyFrom], ['platform' => true]);

        return $template;
    }

    /** Saves the draft only if nobody else saved since $expectedRevision. Returns the new revision. */
    public function saveDraft(SiteTemplate $template, mixed $definition, int $expectedRevision): int
    {
        $clean = TemplateDefinition::validate($definition);

        return DB::transaction(function () use ($template, $clean, $expectedRevision): int {
            $locked = SiteTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            if ($locked->draft_revision !== $expectedRevision) {
                throw new DraftConflict($locked->draft_revision, null);
            }
            $locked->update(['draft' => json_encode($clean), 'draft_revision' => $locked->draft_revision + 1]);

            return $locked->draft_revision;
        });
    }

    public function publish(SiteTemplate $template, ?string $note, ?string $by): SiteTemplateVersion
    {
        $definition = TemplateDefinition::validate(json_decode($template->draft, true)); // never publish an invalid draft

        $version = DB::transaction(function () use ($template, $definition, $note, $by): SiteTemplateVersion {
            $locked = SiteTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $number = (int) SiteTemplateVersion::query()->where('template_id', $locked->id)->max('number') + 1;
            $version = SiteTemplateVersion::query()->create([
                'template_id' => $locked->id, 'number' => $number, 'definition' => json_encode($definition), 'note' => $note, 'published_by' => $by,
            ]);
            $locked->update(['status' => 'published', 'published_version' => $number]);

            return $version;
        });
        $this->audit->record('templates.published', 'site_template', $template->id, ['key' => $template->key, 'version' => $version->number, 'note' => $note], ['platform' => true]);

        return $version;
    }

    public function retire(SiteTemplate $template, ?string $by): void
    {
        $template->update(['status' => 'retired']);
        $this->audit->record('templates.retired', 'site_template', $template->id, ['key' => $template->key], ['platform' => true]);
    }

    /**
     * The published definition businesses receive.
     *
     * @return array{template: SiteTemplate, version: int, definition: array<string, mixed>}|null
     */
    public function published(string $key): ?array
    {
        $template = SiteTemplate::query()->with('category')->where('key', $key)->where('status', 'published')->first();
        if ($template === null || $template->published_version === null || ! $template->category?->active) {
            return null;
        }
        $definition = SiteTemplateVersion::query()->where('template_id', $template->id)->where('number', $template->published_version)->value('definition');

        return $definition === null ? null : ['template' => $template, 'version' => $template->published_version, 'definition' => (array) json_decode((string) $definition, true)];
    }

    /** @return list<array{key: string, name: string, description: string|null, icon: string, templates: list<array{key: string, name: string, description: string|null}>}> */
    public function catalogue(): array
    {
        /** @var list<array{key: string, name: string, description: string|null, icon: string, templates: list<array{key: string, name: string, description: string|null}>}> */
        $catalogue = SiteCategory::query()->where('active', true)->orderBy('position')->get()
            ->map(fn (SiteCategory $c) => [
                'key' => $c->key, 'name' => $c->name, 'description' => $c->description, 'icon' => $c->icon,
                'templates' => SiteTemplate::query()->where('category_id', $c->id)->where('status', 'published')->orderBy('position')->get(['key', 'name', 'description'])
                    ->map(fn (SiteTemplate $t) => ['key' => $t->key, 'name' => $t->name, 'description' => $t->description])->all(),
            ])
            ->filter(fn (array $c) => $c['templates'] !== []) // a category appears only once it has a template
            ->values()->all();

        return $catalogue;
    }
}
