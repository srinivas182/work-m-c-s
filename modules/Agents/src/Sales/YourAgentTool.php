<?php

declare(strict_types=1);

namespace Wurk\Agents\Sales;

use Illuminate\Support\Facades\Mail;
use Wurk\Agents\Mail\AgentLeadMail;
use Wurk\Agents\Models\Agent;
use Wurk\Agents\Support\AgentAttribution;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\SalesTool;

/**
 * The sales assistant's hand-over to the agent whose link the visitor followed (19.5): the agent is emailed the
 * visitor's details (with their consent), and keeps the business when it signs up through the link. Offered only then.
 */
final readonly class YourAgentTool implements SalesTool
{
    public function __construct(private AgentAttribution $attribution, private PeopleDirectory $people) {}

    public function name(): string
    {
        return 'your_agent';
    }

    public function description(): string
    {
        return 'This visitor came through a WURK agent’s link. Without arguments: the agent’s first name. With the visitor’s name, contact, message and agreement: ask the agent to contact them (prefer this to contact_wurk when they want a person to help them get started).';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'contact' => ['type' => 'string', 'description' => 'Phone number or email'],
            'message' => ['type' => 'string'],
            'agreed_to_be_contacted' => ['type' => 'boolean', 'description' => 'True only if they agreed to be contacted by the agent'],
        ]];
    }

    public function available(): bool
    {
        return $this->agent() !== null;
    }

    public function run(array $arguments, array $context): array
    {
        $agent = $this->agent() ?? throw new ReceptionistRefused('No agent referred this visitor. Use contact_wurk instead.');
        $person = $this->people->contacts([$agent->identity_id])[$agent->identity_id] ?? null;
        $first = trim(explode(' ', (string) ($person['name'] ?? 'Your agent'))[0]);
        if (trim((string) ($arguments['name'] ?? '')) === '') {
            return ['agent' => $first, 'tell_the_visitor' => "{$first}, the WURK agent who shared the link, can help them get started."];
        }
        $contact = trim((string) ($arguments['contact'] ?? ''));
        if (filter_var($contact, FILTER_VALIDATE_EMAIL) === false && preg_match('/^[+0-9 ()-]{6,20}$/', $contact) !== 1) {
            throw new ReceptionistRefused('Ask for a phone number or email.');
        }
        if (($arguments['agreed_to_be_contacted'] ?? false) !== true) {
            throw new ReceptionistRefused("Ask whether they agree to be contacted by {$first}.");
        }
        if (! is_string($person['email'] ?? null)) {
            throw new ReceptionistRefused('The agent can’t be reached by email. Use contact_wurk instead.');
        }
        Mail::to($person['email'])->send(new AgentLeadMail($first, mb_substr(trim((string) $arguments['name']), 0, 120), $contact, mb_substr(trim((string) ($arguments['message'] ?? '')), 0, 2000)));

        return ['handed_over' => true, 'agent' => $first, 'tell_the_visitor' => "Sent. {$first} will contact them."];
    }

    private function agent(): ?Agent
    {
        $code = request()->cookie(AgentAttribution::COOKIE);

        return is_string($code) ? $this->attribution->activeByCode($code) : null;
    }
}
