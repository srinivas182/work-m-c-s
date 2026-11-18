<?php

declare(strict_types=1);

namespace Wurk\Messaging\Support;

use Wurk\Messaging\Contracts\WhatsAppAction;
use Wurk\Messaging\Contracts\WhatsAppActions;
use Wurk\Messaging\Contracts\WhatsAppAnswer;

/** Holds what each module's WhatsApp buttons do, by the payload's prefix (21.5). */
final class WhatsAppActionRegistry implements WhatsAppActions
{
    /** @var array<int, class-string<WhatsAppAction>> */
    private array $actions = [];

    public function register(string $action): void
    {
        if (! in_array($action, $this->actions, true)) {
            $this->actions[] = $action;
        }
    }

    public function handle(string $payload, string $from): ?WhatsAppAnswer
    {
        $parts = explode(':', $payload);
        $prefix = array_shift($parts);
        foreach ($this->actions as $class) {
            $action = app($class);
            if ($action->prefix() === $prefix) {
                return $action->handle($parts, $from);
            }
        }

        return null;
    }
}
