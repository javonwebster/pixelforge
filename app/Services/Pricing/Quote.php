<?php

namespace App\Services\Pricing;

/**
 * What a generation will probably cost and the most it can cost, before
 * it is submitted. All amounts are integer nanodollars.
 *
 * Costs are Pixelforge's; prices include our markup. The maximum price is
 * what the balance hold covers.
 */
final readonly class Quote
{
    public function __construct(
        public TokenCounts $estimatedTokens,
        public TokenCounts $maximumTokens,
        public int $estimatedCost,
        public int $maximumCost,
        public int $estimatedPrice,
        public int $maximumPrice,
    ) {}
}
