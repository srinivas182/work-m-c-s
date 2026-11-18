<?php

declare(strict_types=1);

namespace Wurk\Money;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Wurk\Messaging\Events\WhatsAppReceived;
use Wurk\Messaging\Events\WurkWhatsAppReceived;
use Wurk\Money\Console\RemindInvoicesCommand;
use Wurk\Money\Console\WeeklySummariesCommand;
use Wurk\Money\Models\Invoice;
use Wurk\Money\Support\Invoices;
use Wurk\Money\Support\MoneyBook;
use Wurk\Money\Support\WeeklySummary;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Contracts\DashboardTiles;
use Wurk\Platform\Contracts\MoneyOwed;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantContext;

final class MoneyServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();
        $this->loadViewsFrom($this->modulePath('resources/views'), 'money');
        // Who owes me (23.5a): invoices sent and not yet paid.
        $this->callAfterResolving(MoneyOwed::class, fn (MoneyOwed $o) => $o->register('money', fn () => Invoice::query()->where('status', 'sent')->orderBy('due_on')->get()
            ->map(fn (Invoice $i) => ['key' => 'invoice:'.$i->id, 'kind' => 'invoice', 'title' => 'Invoice '.$i->label(), 'customer' => $i->customer_name,
                'phone' => $i->customer_phone, 'email' => $i->customer_email, 'amount_cents' => $i->total_cents, 'since' => $i->issued_on->toDateString(),
                'due' => $i->due_on->toDateString(), 'link' => '/money/owed#invoice-'.$i->id, 'settle' => '/money/invoices/'.$i->id.'/paid'])->values()->all()));
        // Paid by link: confirmed with the provider before it counts.
        Event::listen(BusinessPaymentReceived::class, fn (BusinessPaymentReceived $e) => str_starts_with($e->reference, 'inv_') ? $this->app->make(Invoices::class)->confirm($e->reference) : null);
        // A customer replying STOP to a payment reminder on the business's WhatsApp.
        Event::listen(WhatsAppReceived::class, fn (WhatsAppReceived $e) => strtoupper(trim($e->text)) === 'STOP' ? $this->app->make(Invoices::class)->stop('+'.ltrim($e->from, '+')) : null);
        // An owner's STOP to the Monday summary on WURK's number (23.5b).
        Event::listen(WurkWhatsAppReceived::class, fn (WurkWhatsAppReceived $e) => strtoupper(trim($e->text)) === 'STOP' ? WeeklySummary::stop($e->from) : null);
        // Profit in plain words, in Reports and on Home (23.5b).
        $this->callAfterResolving(BusinessReports::class, fn (BusinessReports $r) => $r->register('money.profit', 650, function (ReportPeriod $p): ?array {
            if (! $p->forOwners && ! Gate::allows('money.manage')) {
                return null;
            }
            $now = app(MoneyBook::class)->profit($p->from, $p->to);
            $before = app(MoneyBook::class)->profit($p->previousFrom(), $p->previousTo());

            return ['title' => 'Profit', 'metrics' => [
                ['label' => 'Took', 'value' => $now['took'], 'previous' => $before['took'], 'format' => 'money'],
                ['label' => 'Spent', 'value' => $now['spent'], 'previous' => $before['spent'], 'format' => 'money'],
                ['label' => 'Made (about)', 'value' => $now['made'], 'previous' => $before['made'], 'format' => 'money'],
            ], 'chart' => null, 'rows' => null, 'extra' => null,
                'highlights' => ['You took '.MoneyBook::rand($now['took']).', spent '.MoneyBook::rand($now['spent']).', so you made about '.MoneyBook::rand($now['made']).'.'],
                'note' => 'Spent is the expenses added in Money → Expenses.'];
        }));
        $this->callAfterResolving(DashboardTiles::class, fn (DashboardTiles $t) => $t->register('money.profit', 8, function (): ?array {
            $context = app(TenantContext::class);
            if (! $context->has() || ! Gate::allows('money.manage')) {
                return null;
            }
            $month = CarbonImmutable::now($context->current()->timezone())->startOfMonth();
            $p = app(MoneyBook::class)->profit($month->utc(), $month->addMonth()->utc());

            return ['label' => 'This month', 'value' => 'Made about '.MoneyBook::rand($p['made']), 'hint' => 'Took '.MoneyBook::rand($p['took']).', spent '.MoneyBook::rand($p['spent']),
                'href' => route('tenant.money.expenses', ['tenant' => $context->current()->handle()])];
        }));
        $this->callAfterResolving(Schedule::class, function (Schedule $s): void {
            $s->command('money:remind-invoices')->dailyAt('09:00')->timezone('Africa/Johannesburg')->onOneServer()->withoutOverlapping();
            $s->command('money:weekly-summaries')->weeklyOn(1, '08:20')->timezone('Africa/Johannesburg')->onOneServer()->withoutOverlapping();
        });
        if ($this->app->runningInConsole()) {
            $this->commands([RemindInvoicesCommand::class, WeeklySummariesCommand::class]);
        }
    }

    public function menu(): array
    {
        return [
            ['label' => 'Who owes me', 'route' => 'tenant.money.owed', 'icon' => 'hand-coins', 'section' => 'Money', 'permission' => 'money.manage', 'order' => 650],
            ['label' => 'Cash-up', 'route' => 'tenant.money.cash-up', 'icon' => 'wallet', 'section' => 'Money', 'permission' => 'money.cashup', 'order' => 651],
            ['label' => 'Expenses and profit', 'route' => 'tenant.money.expenses', 'icon' => 'receipt', 'section' => 'Money', 'permission' => 'money.manage', 'order' => 652],
        ];
    }

    public function permissions(): array
    {
        return [
            ['key' => 'money.manage', 'label' => 'Money: who owes me, invoices, expenses and profit'],
            ['key' => 'money.cashup', 'label' => 'Count the till at the end of the day (cash-up)'],
        ];
    }
}
