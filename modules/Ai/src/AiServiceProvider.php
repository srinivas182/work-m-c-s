<?php

declare(strict_types=1);

namespace Wurk\Ai;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Wurk\Ai\Console\SweepSalesConversationsCommand;
use Wurk\Ai\Contracts\Ai;
use Wurk\Ai\Contracts\AiActions;
use Wurk\Ai\Contracts\AiConversations;
use Wurk\Ai\Contracts\AskWurk;
use Wurk\Ai\Contracts\HelpAnswers;
use Wurk\Ai\Http\Middleware\ShareAssistant;
use Wurk\Ai\Support\AiClient;
use Wurk\Ai\Support\AiSettings;
use Wurk\Ai\Support\Assistant;
use Wurk\Ai\Support\CostEstimates;
use Wurk\Ai\Support\CreditedActions;
use Wurk\Ai\Support\FiguresTool;
use Wurk\Ai\Support\HelpAnswerer;
use Wurk\Plans\Contracts\CreditCostEstimates;
use Wurk\Platform\Contracts\AssistantTools;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class AiServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiSettings::class);
        $this->app->singleton(Ai::class, AiClient::class);
        $this->app->singleton(AiConversations::class, AiClient::class); // 19.1: conversations with tools
        $this->app->scoped(AiActions::class, CreditedActions::class);
        $this->app->bind(HelpAnswers::class, HelpAnswerer::class);
        $this->app->bind(AskWurk::class, Assistant::class); // 25.6: the WhatsApp help line
        $this->app->singleton(CreditCostEstimates::class, CostEstimates::class);
    }

    protected function bootModule(): void
    {
        $this->app->make(Router::class)->pushMiddlewareToGroup('tenant.back-office', ShareAssistant::class);
        $this->callAfterResolving(AssistantTools::class, fn (AssistantTools $t) => $t->register(FiguresTool::class));
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('ai:sweep-sales-conversations')->dailyAt('03:35')->onOneServer()->withoutOverlapping(); // 19.5
        });
        if ($this->app->runningInConsole()) {
            $this->commands([SweepSalesConversationsCommand::class]);
        }
    }

    /** @return list<array{key: string, label: string, scope?: string}> */
    public function permissions(): array
    {
        return [
            ['key' => 'ai.manage', 'label' => 'Manage WURK AI (providers’ models, prices, margin, caps)', 'scope' => 'platform'],
            ['key' => 'languages.manage', 'label' => 'Languages: draft with WURK AI, offer or withdraw a language, glossary', 'scope' => 'platform'],
            ['key' => 'languages.review', 'label' => 'Languages: review and correct translations', 'scope' => 'platform'],
            ['key' => 'assistant.whatsapp', 'label' => 'Give WURK instructions on WhatsApp (as themselves, confirmed with Yes)'], // 26.1
        ];
    }

    /** @return list<array<string, mixed>> */
    public function menu(): array
    {
        return [
            ['label' => 'WURK AI', 'route' => 'admin.ai', 'icon' => 'sparkles', 'section' => 'Settings', 'permission' => 'ai.manage', 'order' => 35],
            ['label' => 'Languages', 'route' => 'admin.languages', 'icon' => 'globe', 'section' => 'Settings', 'permission' => 'languages.review', 'order' => 36],
        ];
    }
}
