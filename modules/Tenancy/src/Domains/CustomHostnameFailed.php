<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Domains;

use RuntimeException;

/** The domain service refused a request or couldn't be reached (the message is safe to show the owner). */
final class CustomHostnameFailed extends RuntimeException {}
