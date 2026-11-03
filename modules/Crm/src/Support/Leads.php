<?php

declare(strict_types=1);

namespace Wurk\Crm\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Crm\Contracts\LeadBook;
use Wurk\Crm\Models\Lead;
use Wurk\Crm\Models\Pipeline;
use Wurk\Crm\Models\Stage;
use Wurk\Tenancy\Contracts\TenantContext;

final readonly class Leads implements LeadBook
{
    /** The pipeline every business starts with (stages can be renamed, added and reordered). */
    public const DEFAULT_STAGES = [['New', 'open'], ['Contacted', 'open'], ['Quoted', 'open'], ['Won', 'won'], ['Lost', 'lost']];

    public function __construct(private TenantContext $context) {}

    /** The business's first pipeline, created with the default stages the first time it's needed. */
    public function pipeline(): Pipeline
    {
        $p = Pipeline::query()->orderBy('position')->first();
        if ($p !== null) {
            return $p;
        }
        $p = Pipeline::query()->create(['name' => 'Sales', 'position' => 0]);
        foreach (self::DEFAULT_STAGES as $i => [$name, $kind]) {
            Stage::query()->create(['pipeline_id' => $p->id, 'name' => $name, 'kind' => $kind, 'position' => $i]);
        }

        return $p;
    }

    public function open(string $contactId, string $title, string $source, ?int $valueCents = null, ?string $branchId = null, ?string $note = null): string
    {
        $p = $this->pipeline();
        $first = Stage::query()->where('pipeline_id', $p->id)->orderBy('position')->firstOrFail();
        $lead = Lead::query()->create(['contact_id' => $contactId, 'pipeline_id' => $p->id, 'stage_id' => $first->id, 'title' => mb_substr($title, 0, 160),
            'value_cents' => $valueCents, 'source' => $source, 'branch_id' => $branchId]);
        $this->log($lead, 'created', $note, null);

        return $lead->id;
    }

    /** Move a lead to a stage of its pipeline; won or lost stages close it, open ones reopen it. */
    public function move(Lead $lead, string $stageId, ?string $by): void
    {
        $to = Stage::query()->where('pipeline_id', $lead->pipeline_id)->whereKey($stageId)->firstOrFail();
        if ($to->id === $lead->stage_id) {
            return;
        }
        $lead->update(['stage_id' => $to->id, 'closed_at' => $to->kind === 'open' ? null : ($lead->closed_at ?? now())]);
        $this->log($lead, 'moved', $to->name, $by);
    }

    public function assign(Lead $lead, ?string $memberId, ?string $name, ?string $by): void
    {
        $lead->update(['assigned_to' => $memberId]);
        $this->log($lead, 'assigned', $name ?? 'No one', $by);
    }

    public function followUp(Lead $lead, ?string $at, ?string $by): void
    {
        $lead->update(['follow_up_at' => $at, 'reminded_at' => null]); // a new date gets its own reminder
        $this->log($lead, 'follow_up', $at, $by);
    }

    public function note(Lead $lead, string $text, ?string $by): void
    {
        $this->log($lead, 'note', $text, $by);
        $lead->touch();
    }

    private function log(Lead $lead, string $event, ?string $detail, ?string $by): void
    {
        DB::connection($this->context->connectionName())->table('lead_events')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'lead_id' => $lead->id, 'event' => $event,
            'detail' => $detail === null ? null : mb_substr($detail, 0, 1000), 'by' => $by, 'at' => now(),
        ]);
    }
}
