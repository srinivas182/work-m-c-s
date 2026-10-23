<?php

declare(strict_types=1);

namespace Wurk\Website\Console;

use Illuminate\Console\Command;
use Wurk\Website\Models\SiteCategory;
use Wurk\Website\Models\SiteTemplate;
use Wurk\Website\Support\Templates;

/**
 * Development and browser tests only: publishes simple sample designs so signup
 * shows the category and design steps. Real templates are built in Sprint 6.4.
 */
final class SeedSampleTemplatesCommand extends Command
{
    protected $signature = 'website:seed-sample-templates';

    protected $description = 'Publish sample templates for development and browser tests (refused in production)';

    public function handle(Templates $templates): int
    {
        if ($this->laravel->environment('production')) {
            $this->components->error('Sample templates are never created in production.');

            return self::FAILURE;
        }
        $this->call('website:seed-categories');

        foreach ([
            ['salon-beauty', 'sample-salon-warm', 'Warm salon (sample)', 'split', 'classic'],
            ['salon-beauty', 'sample-salon-bold', 'Bold salon (sample)', 'centered', 'editorial'],
            ['health', 'sample-dental', 'Dental practice (sample)', 'centered', 'modern'],
        ] as [$category, $key, $name, $layout, $font]) {
            if (SiteTemplate::query()->where('key', $key)->exists()) {
                continue;
            }
            $template = $templates->create(SiteCategory::query()->where('key', $category)->firstOrFail(), $key, $name, 'Sample design for development', null, null);
            $templates->saveDraft($template, self::definition($layout, $font), 1);
            $templates->publish($template->fresh() ?? $template, 'Sample', null);
        }
        $this->components->info('Sample templates published.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private static function definition(string $layout, string $font): array
    {
        $prices = ['id' => 'prices', 'type' => 'ServicesPrices', 'version' => 1, 'props' => ['heading' => 'Services and prices', 'items' => [['name' => 'Sample service', 'description' => '', 'duration' => '1 hr', 'price' => 35000, 'from' => false]]]];

        return [
            'pages' => [
                ['key' => 'home', 'title' => 'Home', 'slug' => 'home', 'is_home' => true, 'content' => ['schema' => 1, 'blocks' => [
                    ['id' => 'header', 'type' => 'Header', 'version' => 1, 'props' => []],
                    ['id' => 'hero', 'type' => 'Hero', 'version' => 1, 'props' => ['layout' => $layout, 'heading' => 'Your business, at its best']],
                    $prices,
                    ['id' => 'footer', 'type' => 'Footer', 'version' => 1, 'props' => []],
                ]]],
                ['key' => 'prices', 'title' => 'Prices', 'slug' => 'prices', 'is_home' => false, 'content' => ['schema' => 1, 'blocks' => [
                    ['id' => 'header', 'type' => 'Header', 'version' => 1, 'props' => []], $prices, ['id' => 'footer', 'type' => 'Footer', 'version' => 1, 'props' => []],
                ]]],
            ],
            'menus' => ['header' => [['label' => 'Prices', 'type' => 'page', 'page' => 'prices']], 'footer' => []],
            'branding' => ['primary' => '#9D174D', 'secondary' => null, 'font_pairing' => $font, 'corners' => 'soft'],
        ];
    }
}
