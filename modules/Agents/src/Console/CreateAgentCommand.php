<?php

declare(strict_types=1);

namespace Wurk\Agents\Console;

use Illuminate\Console\Command;
use Wurk\Agents\Models\Agent;
use Wurk\Agents\Support\AgentRoster;

/** Make someone an agent from the command line (setup and support) — the same as Admin → Agents → Add. */
final class CreateAgentCommand extends Command
{
    protected $signature = 'agents:create {contact : Their phone number or email} {--first=Agent} {--last=WURK} {--sponsor= : The sponsor agent\'s code}';

    protected $description = 'Make a person (new or existing WURK ID) a WURK agent';

    public function handle(AgentRoster $roster): int
    {
        $sponsor = null;
        if (is_string($this->option('sponsor')) && $this->option('sponsor') !== '') {
            $sponsor = Agent::query()->where('code', mb_strtoupper((string) $this->option('sponsor')))->value('id');
            if ($sponsor === null) {
                $this->error('No agent with that code.');

                return self::FAILURE;
            }
        }
        try {
            $agent = $roster->create((string) $this->argument('contact'), (string) $this->option('first'), (string) $this->option('last'), $sponsor === null ? null : (string) $sponsor, null);
        } catch (\DomainException $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS; // already an agent: fine for setup scripts
        }
        $this->info("Agent {$agent->code} created.");

        return self::SUCCESS;
    }
}
