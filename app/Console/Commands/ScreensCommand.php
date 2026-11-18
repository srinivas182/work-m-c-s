<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;
use Wurk\Records\Models\Patient;
use Wurk\Records\Support\PatientAccess;
use Wurk\Support\Support\Tickets;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Watchtower\Support\Recorder;
use Wurk\Watchtower\Support\Signal;

/**
 * Sprint 18.5a — every screen, for the accessibility crawl (e2e/a11y-screens.spec.ts). Creates a business with a
 * record behind every detail screen (a customer, a lead, a campaign, a patient, an order, a support ticket, a page, a
 * booking, a class place, a table, a payment), then writes storage/app/e2e-screens.json: the URL of **every GET route**
 * that shows a page, grouped by who sees it. A route that isn't a page is listed in NOT_SCREENS with the reason, and a
 * new route that can't be resolved makes the crawl fail — so no screen is left out. Never in production.
 */
final class ScreensCommand extends Command
{
    public const HANDLE = 'screens-1';

    /** Reviewed (Sprint 18.5a): GET routes that aren't screens — files, data for scripts, redirects, provider hand-offs. */
    public const NOT_SCREENS = [
        'agents.link' => 'redirects to signup with the agent', 'referrals.link' => 'redirects to the site with the referrer',
        'campaigns.link' => 'redirects to the campaign link', 'campaigns.open' => 'tracking pixel', 'my.open' => 'redirects to the business',
        'website.public.robots' => 'text', 'website.public.sitemap' => 'XML', 'website.public.llms' => 'text',
        'portal.handoff' => 'redirect', 'portal.id-qr' => 'image', 'portal.qr' => 'image', 'portal.my-data' => 'JSON download', 'portal.wallet-pass' => 'wallet file',
        'agent.demo.qr' => 'image', 'agent.qr' => 'image', 'admin.agents.qr' => 'image', 'tenant.referrals.qr' => 'image',
        'admin.agents.payouts.csv' => 'CSV', 'admin.metrics.export' => 'CSV', 'admin.reconciliation.export' => 'CSV', 'admin.health.export' => 'CSV',
        'tenant.contacts.export' => 'CSV', 'tenant.credits.statement' => 'CSV', 'tenant.reports.export' => 'CSV/PDF', 'tenant.data.download' => 'ZIP',
        'admin.businesses.invoice' => 'PDF', 'tenant.billing.invoice' => 'PDF', 'tenant.billing.credit-note' => 'PDF',
        'records.public.file' => 'file', 'tenant.records.file' => 'file', 'admin.support.file' => 'file', 'tenant.support.file' => 'file', 'tenant.website.enquiries.file' => 'file',
        'admin.businesses.check-handle' => 'JSON', 'signup.check-handle' => 'JSON', 'tenant.contacts.search' => 'JSON', 'tenant.campaigns.estimate' => 'JSON',
        'tenant.sales.find' => 'JSON', 'tenant.billing.change.quote' => 'JSON', 'tenant.website.ai.quote' => 'JSON', 'tenant.website.draft' => 'JSON',
        'tenant.assistant' => 'JSON for Ask WURK', 'website.public.chat' => 'JSON for the chat widget', 'marketing.sales' => 'JSON for WURK’s sales chat (19.5)', 'tenant.website.versions' => 'JSON for the editor',
        'tenant.website.photos' => 'JSON for the photo picker', 'tenant.website.videos' => 'JSON for the video picker (20.2)', 'tenant.shop.photos' => 'JSON for the photo picker', 'admin.templates.versions' => 'JSON for the editor',
        'admin.templates.photos' => 'JSON for the photo picker', 'help.panel.app' => 'JSON for the help panel', 'help.panel.admin' => 'JSON for the help panel', 'help.search' => 'JSON',
        'bookings.public.services' => 'JSON for the booking widget', 'bookings.public.times' => 'JSON for the booking widget', 'bookings.public.tables.times' => 'JSON for the booking widget',
        'bookings.public.classes' => 'JSON for the booking widget', 'tenant.website.chat' => 'JSON for the chat widget',
        'tenant.billing.return' => 'redirect back from the payment provider', 'bookings.public.paid' => 'redirect back from the payment provider',
        'bookings.video.callback' => 'OAuth callback', 'tenant.bookings.video.connect' => 'redirect to Google/Microsoft/Zoom',
        'payments.wurk.pay' => 'hands over to the payment provider (auto-submitting form)', 'payments.public.pay' => 'hands over to the payment provider (auto-submitting form)',
        'webhooks.meta.verify' => 'webhook', 'health.live' => 'health check', 'health.ready' => 'health check', 'health.heartbeat' => 'health check',
        'website.block-board' => 'internal design board', 'platform.design' => 'internal design showcase', 'tenant.website.preview' => 'the business’s own site (covered by the public site)',
        'campaigns.stop' => 'needs a sent campaign message (covered by the Campaigns feature tests)', 'identity.invitation' => 'needs an invitation token (covered by the team e2e)',
        'signup.claim' => 'needs a demo claim token (covered by the agent e2e)', 'website.template-preview' => 'a template’s site (covered by the templates e2e)',
        'identity.confirm' => 'only for a signed-in person mid-action (covered by Identity feature tests)', 'identity.forgot.code' => 'only mid-reset', 'identity.forgot.new' => 'only mid-reset',
        'identity.code' => 'only mid-sign-in (covered by the sign-in e2e)', 'identity.mfa' => 'only mid-sign-in (covered by the admin e2e)', 'identity.mfa.setup' => 'only mid-sign-in',
        'identity.password' => 'only mid-sign-in (covered by the sign-in e2e)', 'records.public.show' => 'the patient’s link (covered by the health-practice e2e)',
    ];

    protected $signature = 'wurk:screens';

    protected $description = 'Create a business with a record behind every screen, and list every screen for the accessibility crawl (not in production)';

    public function handle(Router $router, TenantDirectory $directory, TenantContext $context): int
    {
        if (app()->isProduction()) {
            $this->components->error('Never in production.');

            return self::FAILURE;
        }
        if ($directory->findByHandle(self::HANDLE) === null) {
            Artisan::call('wurk:seed-load', ['--businesses' => 1, '--prefix' => 'screens', '--customers' => 12, '--bookings' => 6, '--products' => 3]);
        }
        $tenant = $directory->findByHandle(self::HANDLE) ?? throw new \RuntimeException('No screens business.');
        $ids = $context->run($tenant, fn (): array => $this->records($context, $tenant->tenantId()));
        $ids += $this->central($tenant->tenantId());

        $screens = [];
        $unresolved = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || isset(self::NOT_SCREENS[(string) $route->getName()]) || str_starts_with((string) $route->getName(), 'security.txt.')) {
                continue;
            }
            $area = $this->area($route);
            if ($area === null) {
                continue;
            }
            try {
                $url = $this->url($route, $tenant->handle(), $tenant->tenantId(), $ids);
            } catch (Throwable $e) {
                $unresolved[] = ['name' => (string) $route->getName(), 'uri' => $route->uri(), 'why' => $e->getMessage()];

                continue;
            }
            $screens[] = ['area' => $area, 'name' => (string) $route->getName(), 'url' => $url];
        }
        usort($screens, fn ($a, $b) => [$a['area'], $a['name']] <=> [$b['area'], $b['name']]);
        file_put_contents(storage_path('app/e2e-screens.json'), json_encode([
            'handle' => self::HANDLE, 'owner' => 'owner@'.self::HANDLE.'.test', 'password' => SeedLoadCommand::PASSWORD,
            'screens' => $screens, 'unresolved' => $unresolved,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->components->info(count($screens).' screens, '.count($unresolved).' unresolved; written to storage/app/e2e-screens.json.');

        return $unresolved === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Who sees the route: owner (back office), staff (Admin), agent, guest (signed out), visitor (a business's site). */
    private function area(Route $route): ?string
    {
        $domain = (string) $route->getDomain();
        $uri = $route->uri();
        $mw = $route->gatherMiddleware();

        return match (true) {
            $domain === (string) config('wurk.domains.admin') => 'staff',
            str_starts_with($domain, '{handle}.') => 'visitor',
            in_array($domain, [(string) config('wurk.domains.root'), (string) config('wurk.domains.help'), (string) config('wurk.domains.my')], true) => in_array('auth', $mw, true) ? 'owner' : 'guest',
            str_starts_with($uri, '{tenant}') || in_array($uri, ['businesses', 'account/security'], true) => 'owner',
            str_starts_with($uri, 'agent') => 'agent',
            $domain === '' || $domain === (string) config('wurk.domains.app') => in_array('auth', $mw, true) ? 'owner' : 'guest',
            default => null,
        };
    }

    /** @param array<string, string> $ids */
    private function url(Route $route, string $handle, string $tenantId, array $ids): string
    {
        $params = [];
        foreach ($route->parameterNames() as $p) {
            $params[$p] = match (true) {
                $p === 'tenant' && str_starts_with((string) $route->getName(), 'admin.') => $tenantId,
                $p === 'handle' && in_array($route->getName(), ['website.public.home', 'website.public.page'], true) => $ids['site_handle'] ?? throw new \RuntimeException('No published site'),
                $p === 'tenant', $p === 'handle' => $handle,
                $p === 'fallbackPlaceholder' => $ids['site_page'] ?? throw new \RuntimeException('No published page'),
                $p === 'page' && $route->getName() === 'marketing.legal' => 'privacy',
                $p === 'page' && str_starts_with((string) $route->getName(), 'admin.templates') => $ids['template_page'] ?? throw new \RuntimeException('No template page'),
                $p === 'slug' && $route->getName() === 'help.article' => $ids['help_article_slug'] ?? throw new \RuntimeException('No help article'),
                $p === 'slug' && $route->getName() === 'help.category' => $ids['help_category_slug'] ?? throw new \RuntimeException('No help category'),
                $p === 'number' => $ids['ticket_number'],
                isset($ids[$p]) => $ids[$p],
                default => throw new \RuntimeException("No value for {{$p}}"),
            };
        }
        if ($route->getName() === 'bookings.public.manage') { // checks its own signature, valid until the booking starts
            return URL::temporarySignedRoute('bookings.public.manage', now()->addDays(7), $params);
        }
        $signed = in_array('signed', $route->gatherMiddleware(), true);

        return $signed ? URL::signedRoute((string) $route->getName(), $params) : route((string) $route->getName(), $params);
    }

    /**
     * One of each record behind a detail screen (once).
     *
     * @return array<string, string>
     */
    private function records(TenantContext $context, string $tenantId): array
    {
        $db = DB::connection($context->connectionName());
        $id = fn (): string => strtolower((string) Str::ulid());
        $now = now();
        $first = fn (string $table, string $col = 'id') => $db->table($table)->where('tenant_id', $tenantId)->orderBy($col)->value($col);
        $contact = (string) $first('contacts');
        $service = (string) $first('booking_services');
        $member = (string) $first('members');

        if ($first('pipelines') === null) {
            $db->beginTransaction(); // all or nothing, so a second run never finds half of them
            $pipeline = $id();
            $db->table('pipelines')->insert(['id' => $pipeline, 'tenant_id' => $tenantId, 'name' => 'Sales', 'position' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $db->table('pipeline_stages')->insert(['id' => $stage = $id(), 'tenant_id' => $tenantId, 'pipeline_id' => $pipeline, 'name' => 'New', 'kind' => 'open', 'position' => 0]);
            $db->table('leads')->insert(['id' => $id(), 'tenant_id' => $tenantId, 'contact_id' => $contact, 'pipeline_id' => $pipeline, 'stage_id' => $stage,
                'title' => 'Wedding party, 6 people', 'created_at' => $now, 'updated_at' => $now]);
            $db->table('campaigns')->insert(['id' => $id(), 'tenant_id' => $tenantId, 'name' => 'Spring specials', 'channel' => 'email', 'segment' => 'all',
                'subject' => 'Spring is here', 'body' => 'Book this week and get 10% off.', 'created_at' => $now, 'updated_at' => $now]);
            $patient = Patient::query()->create(['name' => 'Naledi Dlamini', 'email' => 'naledi@example.test']);
            app(PatientAccess::class)->sendLink($patient, 'screens');
            $product = (string) $first('shop_products');
            $variant = (string) $db->table('shop_variants')->where('product_id', $product)->value('id');
            $db->table('shop_orders')->insert(['id' => $order = $id(), 'tenant_id' => $tenantId, 'number' => 1001, 'status' => 'paid', 'payment_method' => 'card',
                'fulfilment' => 'collect', 'customer_name' => 'Customer 1', 'customer_phone' => '+27820000001', 'subtotal_cents' => 15000, 'total_cents' => 15000,
                'created_at' => $now, 'updated_at' => $now]);
            $db->table('shop_order_items')->insert(['id' => $id(), 'tenant_id' => $tenantId, 'order_id' => $order, 'variant_id' => $variant, 'product_name' => 'Product 1',
                'unit_cents' => 15000, 'quantity' => 1, 'stock_tracked' => false, 'line_cents' => 15000]);
            $db->table('pages')->insert(['id' => $id(), 'tenant_id' => $tenantId, 'title' => 'About us', 'slug' => 'about', 'draft' => json_encode(['blocks' => []]),
                'created_at' => $now, 'updated_at' => $now]);
            $db->table('class_sessions')->insert(['id' => $session = $id(), 'tenant_id' => $tenantId, 'title' => 'Morning yoga', 'starts_at' => $now->copy()->addDays(2)->setTime(7, 0),
                'ends_at' => $now->copy()->addDays(2)->setTime(8, 0), 'capacity' => 12, 'created_at' => $now, 'updated_at' => $now]);
            $db->table('class_attendees')->insert(['id' => $id(), 'tenant_id' => $tenantId, 'session_id' => $session, 'customer_name' => 'Customer 2', 'created_at' => $now, 'updated_at' => $now]);
            $db->table('reservations')->insert(['id' => $id(), 'tenant_id' => $tenantId, 'party_size' => 4, 'service_date' => $now->copy()->addDay()->toDateString(),
                'starts_at' => $now->copy()->addDay()->setTime(19, 0), 'ends_at' => $now->copy()->addDay()->setTime(21, 0), 'status' => 'confirmed', 'customer_name' => 'Customer 3',
                'created_at' => $now, 'updated_at' => $now]);
            $db->table('payment_attempts')->insert(['reference' => $id(), 'tenant_id' => $tenantId, 'provider' => 'payfast', 'amount_cents' => 15000, 'created_at' => $now, 'updated_at' => $now]);
            $db->table('enquiries')->insert(['id' => $id(), 'tenant_id' => $tenantId, 'name' => 'Customer 4', 'message' => 'Do you have time on Saturday?',
                'consented_at' => $now, 'ip_hash' => hash('sha256', '127.0.0.1'), 'created_at' => $now]);
            unset($service, $member);
            // Patient records are part of a health practice.
            $db->table('booking_settings')->updateOrInsert(['tenant_id' => $tenantId], ['health_practice' => true, 'created_at' => $now, 'updated_at' => $now]);
            $db->commit();
        }

        return array_filter(['contact' => $contact, 'lead' => (string) $first('leads'), 'campaign' => (string) $first('campaigns'), 'patient' => (string) $first('record_patients'),
            'order' => (string) $first('shop_orders'),
            'booking' => (string) $db->table('bookings')->where('tenant_id', $tenantId)->where('starts_at', '>', now()->addHour())->orderBy('starts_at')->value('id'), 'attendee' => (string) $first('class_attendees'),
            'reservation' => (string) $first('reservations'), 'page' => (string) $first('pages'), 'reference' => (string) $first('payment_attempts', 'reference')]);
    }

    /**
     * Central records: a support ticket, a help article, a Watchtower problem.
     *
     * @return array<string, string>
     */
    private function central(string $tenantId): array
    {
        if (! DB::table('support_tickets')->where('tenant_id', $tenantId)->exists()) {
            $owner = (string) DB::table('identities')->where('email', 'owner@'.self::HANDLE.'.test')->value('id');
            app(Tickets::class)->open($tenantId, $owner, 'Can’t connect PayFast', 'It says my merchant key is wrong.', [], []);
        }
        if (! DB::table('help_articles')->exists()) {
            DB::table('help_categories')->insert(['id' => $cat = strtolower((string) Str::ulid()), 'slug' => 'getting-started', 'name' => 'Getting started', 'position' => 0,
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('help_articles')->insert(['id' => strtolower((string) Str::ulid()), 'category_id' => $cat, 'slug' => 'your-first-booking', 'title' => 'Your first booking',
                'body' => 'Open Bookings and choose a time.', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        }
        if (! DB::table('watchtower_incidents')->exists()) {
            app(Recorder::class)->record(new Signal('exception', 'screens-sample', 'RuntimeException: Sample problem for the accessibility crawl', message: 'Sample problem',
                location: 'GET {tenant}/bookings', surface: 'web'));
        }
        $template = DB::table('site_templates')->orderBy('key')->first();
        $page = $template === null ? null : (collect((array) (json_decode((string) $template->draft, true)['pages'] ?? []))->first()['key'] ?? null);
        $article = DB::table('help_articles')->orderBy('id')->first();
        $site = $this->publishedSite();

        return array_filter([
            'ticket_number' => (string) DB::table('support_tickets')->where('tenant_id', $tenantId)->value('number'),
            'help_article_slug' => $article === null ? null : (string) $article->slug, 'article' => $article === null ? null : (string) $article->id,
            'help_category_slug' => (string) DB::table('help_categories')->orderBy('position')->value('slug'),
            'incident' => (string) DB::table('watchtower_incidents')->orderBy('id')->value('id'),
            'package' => (string) DB::table('packages')->orderBy('display_order')->value('id'),
            'site_handle' => $site['handle'] ?? null, 'site_page' => $site['page'] ?? null,
            'template' => $template === null ? null : (string) $template->key, 'template_page' => $page === null ? null : (string) $page,
        ]);
    }

    /**
     * The public site screens use a published demo site (the screens business has no published site).
     *
     * @return array{handle?: string, page?: string}
     */
    private function publishedSite(): array
    {
        $context = app(TenantContext::class);
        $ids = array_values(array_map('strval', DB::table('tenants')->where('handle', 'like', 'demo-%')->orderBy('handle')->pluck('id')->all()));
        foreach (app(TenantDirectory::class)->activeAmong($ids) as $tenant) {
            $page = $context->run($tenant, fn () => DB::connection($context->connectionName())->table('pages')->where('tenant_id', $tenant->tenantId())
                ->whereNotNull('published_version_id')->where('is_home', false)->whereNull('deleted_at')->orderBy('position')->value('slug'));
            if ($page !== null) {
                return ['handle' => $tenant->handle(), 'page' => (string) $page];
            }
        }

        return [];
    }
}
