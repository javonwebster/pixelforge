<?php

namespace App\Exceptions;

use App\Support\Nanodollars;
use Brick\Math\RoundingMode;
use RuntimeException;

/**
 * The user's available balance can't cover a generation's maximum price.
 * Nothing was created.
 */
final class InsufficientBalanceException extends RuntimeException
{
    public function __construct(
        public readonly int $requiredNanos,
        public readonly int $availableNanos,
    ) {
        // Rounded so the message can never read "needs $0.09, balance
        // $0.09": what's needed rounds up, what's available rounds down.
        parent::__construct(sprintf(
            'This needs up to %s. Your balance is %s. Buy credits to continue.',
            Nanodollars::format($requiredNanos, rounding: RoundingMode::Ceiling),
            Nanodollars::format($availableNanos, rounding: RoundingMode::Floor),
        ));
    }
}
