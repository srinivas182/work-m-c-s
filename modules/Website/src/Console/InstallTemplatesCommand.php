<?php

declare(strict_types=1);

namespace Wurk\Website\Console;

use Illuminate\Console\Command;
use Wurk\Website\Models\SiteCategory;
use Wurk\Website\Models\SiteTemplate;
use Wurk\Website\Support\LegalPages;
use Wurk\Website\Support\TemplateDefinition;
use Wurk\Website\Support\Templates;

/**
 * Installs WURK's templates from modules/Website/templates/*.json. Creates missing ones
 * and publishes a new version only when a file has changed (fingerprint), so it is safe
 * to run on every deploy. Never deletes or retires anything.
 */
final class InstallTemplatesCommand extends Command
{
    protected $signature = 'website:install-templates';

    protected $description = 'Install or update WURK’s website templates from the repository';

    public function handle(Templates $templates): int
    {
        $this->call('website:seed-categories');
        $created = $updated = 0;
        foreach (glob(base_path('modules/Website/templates/*.json')) ?: [] as $file) {
            $source = (array) json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $definition = (array) $source['definition'];
            $definition['pages'] = [...(array) $definition['pages'], ...LegalPages::pages()];
            $definition = TemplateDefinition::validate($definition);
            $hash = hash('sha256', (string) json_encode([$source['name'], $source['description'] ?? null, $definition]));

            $template = SiteTemplate::query()->where('key', $source['key'])->first();
            if ($template !== null && $template->source_hash === $hash) {
                continue;
            }
            if ($template === null) {
                $category = SiteCategory::query()->where('key', $source['category'])->firstOrFail();
                $template = $templates->create($category, (string) $source['key'], (string) $source['name'], $source['description'] ?? null, null, null);
                $created++;
            } else {
                $template->update(['name' => $source['name'], 'description' => $source['description'] ?? null]);
                $updated++;
            }
            $template->refresh();
            $templates->saveDraft($template, $definition, $template->draft_revision);
            $templates->publish($template->refresh(), 'Installed from WURK’s templates', null);
            $template->forceFill(['source_hash' => $hash, 'position' => (int) ($source['position'] ?? $template->position)])->save();
        }
        $this->components->info("{$created} templates created, {$updated} updated.");

        return self::SUCCESS;
    }
}
