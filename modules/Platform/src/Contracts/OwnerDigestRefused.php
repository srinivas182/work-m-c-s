<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

use RuntimeException;

/** A yes or no from the owner's WhatsApp that can't be applied, with the reason in plain words (21.6). */
final class OwnerDigestRefused extends RuntimeException {}
