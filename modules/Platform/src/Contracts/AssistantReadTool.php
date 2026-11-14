<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/** A read-only look at the business's own data, for answering a question. */
interface AssistantReadTool extends AssistantTool
{
    /**
     * Compact plain-text facts for the AI to answer from. Keep personal details of customers out (no names, phone
     * numbers or emails) — the text goes to an AI provider.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws AssistantRefused when the parameters don't make sense (the message is shown)
     */
    public function read(array $params): string;
}
