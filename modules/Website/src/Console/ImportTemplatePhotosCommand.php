<?php

declare(strict_types=1);

namespace Wurk\Website\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Wurk\Website\Models\SiteTemplate;
use Wurk\Website\Support\TemplatePhotoImporter;
use Wurk\Website\Support\TemplatePhotos;

/**
 * Fills designs' photo slots from a list (Sprint 20.3), on a server that can reach Nappy, Pexels and Unsplash:
 * a JSON list of {"template", "slot", "link", "source"?, "photographer"?, "licence"} (source: the photo's page when the link is
 * its image file). Slots that already have the same link are
 * skipped, so it is safe to run again; problems are listed and the rest still import.
 */
final class ImportTemplatePhotosCommand extends Command
{
    protected $signature = 'website:import-template-photos {file : JSON list of {template, slot, link, source?, photographer?, licence}} {--dry-run : Only check the list}';

    protected $description = 'Import demo photos for templates’ photo slots from Nappy, Pexels or Unsplash links';

    public function handle(TemplatePhotoImporter $importer, TemplatePhotos $photos): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }
        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->error('The file must be a JSON list.');

            return self::FAILURE;
        }
        $done = $skipped = 0;
        $problems = [];
        foreach ($rows as $n => $row) {
            $where = '#'.($n + 1).' '.(is_array($row) ? ($row['template'] ?? '?').'/'.($row['slot'] ?? '?') : '');
            $template = is_array($row) ? SiteTemplate::query()->where('key', (string) ($row['template'] ?? ''))->first() : null;
            if ($template === null) {
                $problems[] = "{$where}: no such template";

                continue;
            }
            $slot = (string) ($row['slot'] ?? '');
            $slots = array_column(TemplatePhotos::slots($template->key, (array) json_decode((string) $template->draft, true)), null, 'slot');
            $licence = (string) ($row['licence'] ?? '');
            $link = (string) ($row['link'] ?? '');
            if (! isset($slots[$slot])) {
                $problems[] = "{$where}: the design has no such photo place";
            } elseif (! in_array($licence, TemplatePhotoImporter::LICENCES, true) || $licence === 'WURK AI') {
                $problems[] = "{$where}: licence must be one of ".implode(', ', array_diff(TemplatePhotoImporter::LICENCES, ['WURK AI']));
            } elseif (! TemplatePhotoImporter::allowed($link)) {
                $problems[] = "{$where}: link must be an https link on Nappy, Pexels or Unsplash";
            } elseif (in_array(($photos->chosen($template)[$slot] ?? null)?->source_url, [$link, $row['source'] ?? null], true)) {
                $skipped++;
            } elseif (! $this->option('dry-run')) {
                try {
                    $importer->fromLink($template, $slot, $link, ['photographer' => $row['photographer'] ?? null, 'licence' => $licence, 'source_url' => is_string($row['source'] ?? null) ? $row['source'] : null], null);
                    $done++;
                } catch (ValidationException $e) {
                    $problems[] = "{$where}: ".collect($e->errors())->flatten()->first();
                }
            }
        }
        foreach ($problems as $p) {
            $this->warn($p);
        }
        $this->info(($this->option('dry-run') ? 'Checked' : "Imported {$done}, skipped {$skipped} already there").'; '.count($problems).' problem(s).');

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
