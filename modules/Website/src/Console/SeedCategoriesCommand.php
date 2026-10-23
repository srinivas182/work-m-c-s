<?php

declare(strict_types=1);

namespace Wurk\Website\Console;

use Illuminate\Console\Command;
use Wurk\Website\Models\SiteCategory;

/** The 12 agreed categories (sprint plan revision 3). Safe to re-run; never overwrites staff edits. */
final class SeedCategoriesCommand extends Command
{
    public const CATEGORIES = [
        ['salon-beauty', 'Salon & beauty', 'Hair, braids, nails, lashes and barbers', 'scissors'],
        ['health', 'Health', 'Dental and doctor’s practices', 'stethoscope'],
        ['spa-wellness', 'Spa & wellness', 'Day spas, therapists and wellness practitioners', 'flower'],
        ['retail', 'Retail', 'Boutiques and general stores', 'shopping-bag'],
        ['fitness', 'Fitness', 'Gyms, studios and personal trainers', 'dumbbell'],
        ['professional-services', 'Professional services', 'Accountants, attorneys, advisers, agencies and consultants', 'briefcase'],
        ['car-mechanics', 'Car mechanics', 'Workshops, service centres, panel beaters and auto electricians', 'wrench'],
        ['hospitality', 'Hospitality', 'Hotels, guesthouses and restaurants', 'utensils'],
        ['trades-home', 'Trades & home services', 'Solar, electrical, plumbing, building, cleaning and more', 'hammer'],
        ['education-training', 'Education & training', 'Tutors, online classes and early-learning centres', 'graduation-cap'],
        ['events-creative', 'Events & creative', 'Photographers, venues and event planners', 'camera'],
        ['transport-logistics', 'Transport & logistics', 'Shuttles, tours, couriers and removals', 'truck'],
    ];

    protected $signature = 'website:seed-categories';

    protected $description = 'Create the agreed website categories (existing ones are left as they are)';

    public function handle(): int
    {
        $created = 0;
        foreach (self::CATEGORIES as $position => [$key, $name, $description, $icon]) {
            $category = SiteCategory::query()->firstOrCreate(['key' => $key], ['name' => $name, 'description' => $description, 'icon' => $icon, 'position' => $position]);
            $created += $category->wasRecentlyCreated ? 1 : 0;
        }
        $this->components->info("{$created} categories created; ".(count(self::CATEGORIES) - $created).' already existed.');

        return self::SUCCESS;
    }
}
