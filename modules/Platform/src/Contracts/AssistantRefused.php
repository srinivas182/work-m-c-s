<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

use RuntimeException;

/** A tool can't do this as asked; the message is shown to the person as is. */
final class AssistantRefused extends RuntimeException {}
