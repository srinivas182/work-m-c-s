<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

use RuntimeException;

/** Something can't be put back (e.g. its time slot was taken); the message is shown as is. */
final class CannotRestore extends RuntimeException {}
