<?php

namespace App\Services\Pricing;

use RuntimeException;

/**
 * A cost didn't come out as a whole number of nanodollars. With rates of
 * up to three decimals this can't happen; if Pixelforge ever publishes
 * finer rates, failing loudly beats silently rounding someone's money.
 */
final class InexactCostException extends RuntimeException {}
