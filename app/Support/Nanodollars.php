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
     * Dollar figure for display, e.g. "$0.09". Users see whole cents; the
     * exact nine-decimal figure is in present()'s `exact` for a hover.
     *
     * Display only: stored amounts and every calculation stay exact.
     * Rounds half up by default; pass Ceiling or Floor where a rounded
     * figure must not mislead (see InsufficientBalanceException).
     *
     * @param  int<0, 9>  $decimals
     */
    public static function format(int $nanos, int $decimals = 2, RoundingMode $rounding = RoundingMode::HalfUp): string
    {
        // Rounded with its sign, so Floor and Ceiling go the right way for
        // negative balances too.
        $amount = BigDecimal::ofUnscaledValue($nanos, self::SCALE)->toScale($decimals, $rounding);

        return ($amount->isNegative() ? '-$' : '$').$amount->abs()->toString();
    }

    /**
     * An amount as the UI needs it: the whole-cent figure users see, and
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
