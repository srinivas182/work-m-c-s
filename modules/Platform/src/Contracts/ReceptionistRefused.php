<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/** A receptionist tool couldn't do what was asked; the message is for the AI to pass on (19.2). */
final class ReceptionistRefused extends \RuntimeException {}
