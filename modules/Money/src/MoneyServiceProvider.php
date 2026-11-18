<?php

declare(strict_types=1);

namespace Wurk\Money;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Wurk\Messaging\Events\WhatsAppReceived;
use Wurk\Money\Console\RemindInvoicesCommand;
use Wurk\Money\Models\Invoice;
use Wurk\Money\Support\Invoices;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Platform\Contracts\MoneyOwed;
use Wurk\Platform\Modules\ModuleServiceProvider;

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
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('money:remind-invoices')->dailyAt('09:00')->timezone('Africa/Johannesburg')->onOneServer()->withoutOverlapping());
        if ($this->app->runningInConsole()) {
            $this->commands([RemindInvoicesCommand::class]);
        }
    }

    public function menu(): array
    {
        return [['label' => 'Who owes me', 'route' => 'tenant.money.owed', 'icon' => 'hand-coins', 'section' => 'Money', 'permission' => 'money.manage', 'order' => 650]];
    }

    public function permissions(): array
    {
        return [['key' => 'money.manage', 'label' => 'Money: who owes me, invoices, cash-ups, expenses and profit']];
    }
}
