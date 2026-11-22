<?php

namespace App\Enums;

/**
 * Quality levels Pixelforge accepts. Medium is its default.
 *
 * The token figures are Pixelforge's documented ones: output tokens are
 * exact for a size and quality, reasoning tokens are only known after
 * completion but always fall inside the quality's range.
 */
enum Quality: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function outputTokens(Size $size): int
    {
        return match ($this) {
            self::Low => $size->isSquare() ? 280 : 420,
            self::Medium => $size->isSquare() ? 1_100 : 1_650,
            self::High => $size->isSquare() ? 4_300 : 6_450,
        };
    }

    public function minReasoningTokens(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 200,
            self::High => 1_000,
        };
    }

    public function maxReasoningTokens(): int
    {
        return match ($this) {
            self::Low => 500,
            self::Medium => 2_000,
            self::High => 8_000,
        };
    }
}
