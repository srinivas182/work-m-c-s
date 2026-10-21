<?php

declare(strict_types=1);

namespace Wurk\Access\Exceptions;

use DomainException;

/** A change that would break an access rule (e.g. removing the last owner). */
final class AccessRuleViolation extends DomainException {}
