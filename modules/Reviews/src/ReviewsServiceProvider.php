<?php

declare(strict_types=1);

namespace Wurk\Reviews;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Wurk\Messaging\Events\WhatsAppReceived;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Reviews\Console\AskForReviewsCommand;
use Wurk\Reviews\Support\Reviews;

/**
 * Reviews (Sprint 25.3): after a visit or an order one message asks "How was it?". Everyone who answers is offered
 * the same Google link and a private word with the owner — no gating. Reviews show on the site in date order.
 */
final class ReviewsServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();
        $this->loadViewsFrom($this->modulePath('resources/views'), 'reviews');
        // STOP to the business's WhatsApp number: no more review requests to that customer.
        Event::listen(WhatsAppReceived::class, fn (WhatsAppReceived $e) => strtoupper(trim($e->text)) === 'STOP' ? $this->app->make(Reviews::class)->stop('+'.ltrim($e->from, '+')) : null);
        $this->callAfterResolving(Schedule::class, function (Schedule $s): void {
            $s->command('reviews:ask')->hourlyAt(20)->onOneServer()->withoutOverlapping();
        });
        if ($this->app->runningInConsole()) {
            $this->commands([AskForReviewsCommand::class]);
        }
    }

    public function permissions(): array
    {
        return [['key' => 'reviews.manage', 'label' => 'Reviews: read, reply and settings']];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [['label' => 'Reviews', 'route' => 'tenant.reviews', 'icon' => 'star', 'section' => 'Customers', 'permission' => 'reviews.manage', 'order' => 430]];
    }
}
