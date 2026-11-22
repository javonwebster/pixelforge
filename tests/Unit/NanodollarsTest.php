<?php

namespace Tests\Unit;

use App\Support\Nanodollars;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NanodollarsTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function usdStrings(): array
    {
        return [
            'nine decimals' => ['0.373875000', 373_875_000],
            'zero' => ['0.000000000', 0],
            'one nanodollar' => ['0.000000001', 1],
            'whole dollars' => ['5', 5_000_000_000],
            'trailing zeros past nine decimals' => ['0.1000000000', 100_000_000],
            'negative' => ['-0.050000000', -50_000_000],
        ];
    }

    #[DataProvider('usdStrings')]
    public function test_parses_exact_usd_strings(string $usd, int $nanos): void
    {
        $this->assertSame($nanos, Nanodollars::fromUsd($usd));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badUsdStrings(): array
    {
        return [
            'below a nanodollar' => ['0.0000000001'],
            'not a number' => ['abc'],
            'exponent' => ['1e-3'],
            'empty' => [''],
        ];
    }

    #[DataProvider('badUsdStrings')]
    public function test_rejects_inexact_or_malformed_usd(string $usd): void
    {
        $this->expectException(InvalidArgumentException::class);

        Nanodollars::fromUsd($usd);
    }

    public function test_exact_usd_string_keeps_all_nine_decimals(): void
    {
        $this->assertSame('0.086612500', Nanodollars::toUsd(86_612_500));
        $this->assertSame('0.000000000', Nanodollars::toUsd(0));
        $this->assertSame('-0.050000000', Nanodollars::toUsd(-50_000_000));
        $this->assertSame('0.373875000', Nanodollars::toUsd(Nanodollars::fromUsd('0.373875000')));
    }

    public function test_users_see_whole_cents(): void
    {
        $this->assertSame('$0.09', Nanodollars::format(86_612_500));
        $this->assertSame('$0.07', Nanodollars::format(72_500_000));
        $this->assertSame('$0.05', Nanodollars::format(50_000_000));
        $this->assertSame('$5.00', Nanodollars::format(5_000_000_000));
        $this->assertSame('-$0.01', Nanodollars::format(-10_000_000));
        $this->assertSame('$0.01', Nanodollars::format(5_000_000));    // half a cent rounds up
        $this->assertSame('$0.00', Nanodollars::format(4_999_999));
        $this->assertSame('$0.46734375', Nanodollars::format(467_343_750, 8));
    }

    public function test_rounding_direction_can_be_chosen(): void
    {
        $this->assertSame('$0.09', Nanodollars::format(80_000_001, rounding: RoundingMode::Ceiling));
        $this->assertSame('$0.08', Nanodollars::format(89_999_999, rounding: RoundingMode::Floor));
        // Signed rounding: a floor of a tiny debt is a whole cent of debt.
        $this->assertSame('-$0.01', Nanodollars::format(-1, rounding: RoundingMode::Floor));
        $this->assertSame('$0.00', Nanodollars::format(-1, rounding: RoundingMode::Ceiling));
    }

    public function test_present_gives_whole_cents_and_the_exact_figure(): void
    {
        $this->assertSame(
            ['nanos' => 86_612_500, 'display' => '$0.09', 'exact' => '$0.086612500'],
            Nanodollars::present(86_612_500),
        );
    }
}
