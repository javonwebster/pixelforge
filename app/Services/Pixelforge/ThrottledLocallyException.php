<?php

namespace App\Services\Pixelforge;

use RuntimeException;

/**
 * Our own throttle refused to send: the account is at its request budget.
 * Nothing was sent to Pixelforge.
 */
final class ThrottledLocallyException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Pixelforge request budget used up; retry in {$retryAfter}s.");
    }
}
