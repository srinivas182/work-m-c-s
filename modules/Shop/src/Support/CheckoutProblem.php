<?php

declare(strict_types=1);

namespace Wurk\Shop\Support;

use RuntimeException;

/** Checkout can't go ahead — the message is shown to the shopper as is. */
final class CheckoutProblem extends RuntimeException {}
