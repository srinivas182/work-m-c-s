<?php

declare(strict_types=1);

namespace Wurk\Shipping\Support;

use RuntimeException;

/** A courier couldn't do what was asked; the message is safe to show the owner or shopper. */
final class ShippingProblem extends RuntimeException {}
