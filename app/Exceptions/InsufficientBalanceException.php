<?php

namespace App\Exceptions;

use App\Support\Nanodollars;
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
        parent::__construct(sprintf(
            'This needs up to %s. Your balance is %s. Buy credits to continue.',
            Nanodollars::format($requiredNanos),
            Nanodollars::format($availableNanos),
        ));
    }
}
