<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Money is stored as integer nanodollars: 1 USD = 1,000,000,000.
 *
 * That is exactly the precision of Pixelforge's nine-decimal `cost_usd`
 * strings, so provider costs are stored without rounding and SQL sums
 * stay exact. Pixelforge's docs forbid floats for money; nothing here
 * ever produces one.
 */
final class Nanodollars
{
    public const int PER_USD = 1_000_000_000;

    private const int SCALE = 9;

    /**
     * Parse an exact decimal USD string, e.g. Pixelforge's "0.169080000".
     *
     * @throws InvalidArgumentException if the string isn't a plain decimal
     *                                  or has a non-zero digit past the 9th decimal
     */
    public static function fromUsd(string $usd): int
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $usd) !== 1) {
            throw new InvalidArgumentException("Not a decimal USD amount: [{$usd}].");
        }

        try {
            return BigDecimal::of($usd)->toScale(self::SCALE)->withPointMovedRight(self::SCALE)->toInt();
        } catch (MathException $e) {
            throw new InvalidArgumentException("USD amount [{$usd}] is not a whole number of nanodollars.", previous: $e);
        }
    }

    /**
     * Exact USD string with all nine decimals, e.g. "0.086612500".
     * Used where figures are compared with Pixelforge digit for digit.
     */
    public static function toUsd(int $nanos): string
    {
        return BigDecimal::ofUnscaledValue($nanos, self::SCALE)->toString();
    }

    /**
     * Dollar figure for users, e.g. "$0.0866". Display only: rounds half
     * up to $decimals places. Stored amounts are never rounded.
     *
     * @param  int<0, 9>  $decimals
     */
    public static function format(int $nanos, int $decimals = 4): string
    {
        $amount = BigDecimal::ofUnscaledValue(abs($nanos), self::SCALE)
            ->toScale($decimals, RoundingMode::HalfUp)
            ->toString();

        return ($nanos < 0 ? '-$' : '$').$amount;
    }

    /**
     * An amount as the UI needs it: the 4-decimal figure users see, and
     * the exact one for a hover title.
     *
     * @return array{nanos: int, display: string, exact: string}
     */
    public static function present(int $nanos): array
    {
        return [
            'nanos' => $nanos,
            'display' => self::format($nanos),
            'exact' => self::format($nanos, 9),
        ];
    }
}
