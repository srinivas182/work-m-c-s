<?php

declare(strict_types=1);

namespace Wurk\Website\Console;

use Illuminate\Console\Command;
use Wurk\Website\Models\SiteCategory;

/** The agreed categories: 12 (sprint plan revision 3), then more from Sprint 20.4. Safe to re-run; never overwrites staff edits. */
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
        // Sprint 20.4a
        ['barbershop', 'Barbershop', 'Barbers, studios and mobile barbers', 'scissors'],
        ['cleaning', 'Cleaning', 'Home, office and specialist cleaning', 'sparkles'],
        ['car-wash', 'Car wash', 'Car washes, detailing studios and mobile washes', 'droplets'],
        ['catering', 'Catering', 'Event, office and home-kitchen catering', 'chef-hat'],
        // Sprint 20.4b
        ['spaza-grocery', 'Spaza & grocery', 'Corner shops, fresh produce and wholesale', 'store'],
        ['beauty-supplies', 'Beauty supplies', 'Beauty stores, skin care brands and salon wholesale', 'shopping-bag'],
        ['tailoring-fashion', 'Tailoring & fashion', 'Tailors, fashion labels and alterations', 'shirt'],
        ['agriculture', 'Agriculture', 'Farms, agri supplies and co-operatives', 'sprout'],
        // Sprint 20.4c
        ['church-ngo', 'Church & NGO', 'Churches, ministries, charities and NGOs', 'church'],
        ['school', 'School', 'Schools, colleges and skills academies', 'school'],
        ['funeral-services', 'Funeral services', 'Funeral homes, funeral cover and memorials', 'flower-2'],
        ['security', 'Security', 'Armed response, guarding and installations', 'shield-check'],
        // Sprint 20.4d
        ['real-estate', 'Real estate', 'Estate agents, developments and rentals', 'house'],
        ['legal-accounting', 'Legal & accounting', 'Attorneys, accountants and business services', 'scale'],
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
