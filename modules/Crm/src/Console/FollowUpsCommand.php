<?php

declare(strict_types=1);

namespace Wurk\Crm\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Crm\Mail\FollowUpMail;
use Wurk\Crm\Models\Contact;
use Wurk\Crm\Models\Lead;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Daily: email each assignee their open leads due for a follow-up (once per date). */
final class FollowUpsCommand extends Command
{
    protected $signature = 'crm:follow-ups';

    protected $description = 'Remind team members of leads due for a follow-up';

    public function handle(TenantDirectory $directory, TenantContext $context): int
    {
        $sent = 0;
        foreach ($directory->activeIds() as $id) {
            $tenant = $directory->find($id);
            if ($tenant === null) {
                continue;
            }
            $context->run($tenant, function () use ($tenant, &$sent): void {
                $due = Lead::query()->withoutGlobalScopes()->where('tenant_id', $tenant->tenantId())->whereNull('closed_at')->whereNull('reminded_at')
                    ->whereNotNull('assigned_to')->where('follow_up_at', '<=', now())->get();
                if ($due->isEmpty()) {
                    return;
                }
                $identities = array_column(app(TeamDirectory::class)->roster(), 'identity_id', 'id');
                $people = app(PeopleDirectory::class)->contacts(array_values(array_unique(array_filter(array_map(fn (Lead $l) => $identities[(string) $l->assigned_to] ?? null, $due->all())))));
                $names = Contact::query()->whereIn('id', $due->pluck('contact_id'))->pluck('name', 'id');
                foreach ($due->groupBy('assigned_to') as $member => $leads) {
                    $email = $people[$identities[(string) $member] ?? '']['email'] ?? null;
                    if ($email !== null) {
                        Mail::to($email)->queue(new FollowUpMail($tenant->displayName(), array_values($leads->map(fn (Lead $l) => [
                            'title' => $l->title, 'contact' => (string) ($names[$l->contact_id] ?? 'A contact'),
                            'url' => 'https://'.config('wurk.domains.app').'/'.$tenant->handle().'/leads/'.$l->id,
                        ])->all())));
                        $sent += $leads->count();
                    }
                    Lead::query()->whereIn('id', $leads->pluck('id'))->update(['reminded_at' => now()]); // once per date, even without an email
                }
            });
        }
        $this->info("Follow-up reminders: {$sent}.");

        return self::SUCCESS;
    }
}
