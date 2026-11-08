<?php

declare(strict_types=1);

namespace Wurk\Agents;

use Illuminate\Support\Facades\Event;
use Wurk\Agents\Console\CreateAgentCommand;
use Wurk\Agents\Support\AgentAttribution;
use Wurk\Agents\Support\AgentCommission;
use Wurk\Billing\Events\CreditNoteIssued;
use Wurk\Billing\Events\PaymentChargedBack;
use Wurk\Billing\Events\PaymentSucceeded;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Tenancy\Events\TenantProvisioned;

final class AgentsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CreateAgentCommand::class]);
        }
        // A paid WURK subscription: commission for the business's agent and the sponsors above (Sprint 14.3).
        Event::listen(PaymentSucceeded::class, fn (PaymentSucceeded $e) => app(AgentCommission::class)->onPayment($e->paymentId));
        // Refunds within WURK's window, and chargebacks, reverse it (Sprint 14.4).
        Event::listen(CreditNoteIssued::class, fn (CreditNoteIssued $e) => app(AgentCommission::class)->onCredit($e->paymentId, $e->creditedCents, $e->invoiceTotalCents));
        Event::listen(PaymentChargedBack::class, fn (PaymentChargedBack $e) => app(AgentCommission::class)->onChargeback($e->paymentId));
        // A business created during signup is credited to the agent whose link the visitor followed (Sprint 14.2).
        Event::listen(TenantProvisioned::class, function (TenantProvisioned $e): void {
            if (app()->bound('request')) {
                app(AgentAttribution::class)->fromSignup($e->tenantId, request());
            }
        });
    }

    public function menu(): array
    {
        return [
            ['label' => 'Agents', 'route' => 'admin.agents.index', 'icon' => 'users', 'section' => 'Platform', 'permission' => 'agents.view', 'order' => 40],
            ['label' => 'Agent commission', 'route' => 'admin.agents.commission', 'icon' => 'users', 'section' => 'Platform', 'permission' => 'agents.view', 'order' => 41],
        ];
    }

    public function permissions(): array
    {
        return [
            ['key' => 'agents.view', 'label' => 'See agents and their teams', 'scope' => 'platform'],
            ['key' => 'agents.manage', 'label' => 'Create, move and suspend agents', 'scope' => 'platform'],
        ];
    }
}
