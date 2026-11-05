<?php

declare(strict_types=1);

namespace Wurk\Sales\Support;

/** A sale action that can't be done, with a message staff can read. */
final class SaleProblem extends \RuntimeException {}
