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
 * to run on every deploy. Never deletes or retires anything. Then fills the designs' photo places with WURK's chosen
 * demo photos (templates/photos/photos.json; photos already there are skipped), so a new installation looks finished.
 * A photo that can't be fetched is reported and its place keeps its stand-in; the install itself never fails for it.
 */
final class InstallTemplatesCommand extends Command
{
    // --only=key (repeatable): install just those designs — tests use it; deploys install everything.
    protected $signature = 'website:install-templates {--only=* : Install only these template keys} {--no-photos : Don’t import the demo photos}';

    protected $description = 'Install or update WURK’s website templates from the repository';

    public function handle(Templates $templates): int
    {
        $this->call('website:seed-categories');
        $created = $updated = 0;
        foreach (glob(base_path('modules/Website/templates/*.json')) ?: [] as $file) {
            $source = (array) json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            if ($this->option('only') !== [] && ! in_array($source['key'], (array) $this->option('only'), true)) {
                continue;
            }
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
        $this->importPhotos();

        return self::SUCCESS;
    }

    /** WURK's demo photos for the installed designs (off with --no-photos or WURK_TEMPLATE_DEMO_PHOTOS=false). */
    private function importPhotos(): void
    {
        $list = base_path('modules/Website/templates/photos/photos.json');
        if ($this->option('no-photos') || ! (bool) config('wurk.template_demo_photos') || ! is_file($list)) {
            return;
        }
        $this->components->info('Importing demo photos (photos already there are skipped)…');
        if ($this->call('website:import-template-photos', ['file' => $list, '--templates' => (array) $this->option('only')]) !== self::SUCCESS) {
            $this->components->warn('Some demo photos weren’t imported; their places keep the stand-in. Run the install again later, or add them in Admin → Templates.');
        }
    }
}
