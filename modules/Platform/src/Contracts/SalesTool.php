<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * A tool WURK's own sales assistant on wurk.co.za may use (Sprint 19.5), offered by a module without the assistant
 * knowing it: Marketing's contact form, the agent who referred the visitor. It runs centrally (no business), with the
 * visitor's request available for what the visitor's browser holds (an agent's link, for example).
 */
interface SalesTool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> JSON schema of the arguments */
    public function parameters(): array;

    /**
     * Throw ReceptionistRefused with a message for the AI when it can't be done (it reads the message, not the customer).
     *
     * @param  array<string, mixed>  $arguments
     * @param  array{conversation: string}  $context
     * @return array<string, mixed>
     */
    public function run(array $arguments, array $context): array;

    /** Whether to offer it in this conversation (e.g. only when an agent referred the visitor). */
    public function available(): bool;
}
