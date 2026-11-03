<?php

declare(strict_types=1);

namespace Wurk\Crm\Support;

use Illuminate\Support\Facades\DB;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Models\Contact;
use Wurk\Tenancy\Contracts\TenantContext;

final readonly class Contacts implements ContactBook
{
    public function __construct(private TenantContext $context) {}

    public function remember(string $name, ?string $phone, ?string $email, string $sourceType, string $sourceId, ?string $branchId, string $summary, ?string $consentSource = null): string
    {
        $phone = Phone::normalise($phone);
        $email = $email === null || trim($email) === '' ? null : mb_strtolower(trim($email));
        $db = DB::connection($this->context->connectionName());

        return $db->transaction(function () use ($db, $name, $phone, $email, $sourceType, $sourceId, $branchId, $summary, $consentSource): string {
            $contact = $this->match($phone, $email);
            if ($contact === null) {
                $contact = Contact::query()->create(['name' => trim($name) ?: 'Customer', 'phone' => $phone, 'email' => $email]);
            } else { // fill in what was missing; a name, number or email already known is kept
                $contact->fill(array_filter(['phone' => $contact->phone ?? $phone, 'email' => $contact->email ?? $email], fn ($v) => $v !== null));
            }
            if ($consentSource !== null && $contact->marketing_consent_at === null) {
                $contact->fill(['marketing_consent_at' => now(), 'marketing_consent_source' => $consentSource]);
            }
            $contact->fill(['last_seen_at' => now()])->save();
            $link = ['source_type' => $sourceType, 'source_id' => $sourceId];
            $row = ['tenant_id' => $this->context->id(), 'branch_id' => $branchId, 'summary' => mb_substr($summary, 0, 200)];
            $db->table('contact_links')->where($link)->exists()
                ? $db->table('contact_links')->where($link)->update($row) // already linked: keep its contact and history
                : $db->table('contact_links')->insert([...$link, ...$row, 'contact_id' => $contact->id, 'original_contact_id' => $contact->id, 'occurred_at' => now()]);

            return $contact->id;
        });
    }

    /** Exact phone or email (never fuzzy); merged contacts lead to the one they joined. */
    private function match(?string $phone, ?string $email): ?Contact
    {
        if ($phone === null && $email === null) {
            return null;
        }
        $found = Contact::query()->where(fn ($q) => $q->when($phone !== null, fn ($q) => $q->orWhere('phone', $phone))->when($email !== null, fn ($q) => $q->orWhere('email', $email)))
            ->orderBy('created_at')->first();
        for ($hops = 0; $found !== null && $found->merged_into !== null && $hops < 5; $hops++) {
            $found = Contact::query()->find($found->merged_into);
        }

        return $found;
    }

    /** Merge another contact into this one: its links move here, it points here; nothing is deleted. */
    public function merge(Contact $keep, Contact $other): void
    {
        abort_if($keep->id === $other->id || $other->merged_into !== null, 422);
        DB::connection($this->context->connectionName())->transaction(function () use ($keep, $other): void {
            DB::connection($this->context->connectionName())->table('contact_links')->where('contact_id', $other->id)->update(['contact_id' => $keep->id]);
            $keep->fill(array_filter(['phone' => $keep->phone ?? $other->phone, 'email' => $keep->email ?? $other->email,
                'tags' => array_values(array_unique([...($keep->tags ?? []), ...($other->tags ?? [])])),
                'marketing_consent_at' => $keep->marketing_consent_at ?? $other->marketing_consent_at, 'marketing_consent_source' => $keep->marketing_consent_source ?? $other->marketing_consent_source,
            ], fn ($v) => $v !== null))->save();
            $other->update(['merged_into' => $keep->id]);
        });
    }

    /** Undo a merge: the merged contact gets back exactly the records that were first its own. */
    public function split(Contact $merged): void
    {
        abort_if($merged->merged_into === null, 422);
        DB::connection($this->context->connectionName())->transaction(function () use ($merged): void {
            DB::connection($this->context->connectionName())->table('contact_links')->where('original_contact_id', $merged->id)->update(['contact_id' => $merged->id]);
            $merged->update(['merged_into' => null]);
        });
    }
}
