<?php

namespace Tests\Unit;

use App\Support\Nanodollars;
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

    public function test_user_display_shows_four_decimals(): void
    {
        // The spec's examples: "up to $0.0866", "your balance is $0.0500".
        $this->assertSame('$0.0866', Nanodollars::format(86_612_500));
        $this->assertSame('$0.0725', Nanodollars::format(72_500_000));
        $this->assertSame('$0.0500', Nanodollars::format(50_000_000));
        $this->assertSame('$5.0000', Nanodollars::format(5_000_000_000));
        $this->assertSame('-$0.0100', Nanodollars::format(-10_000_000));
        $this->assertSame('$0.0001', Nanodollars::format(50_000));    // half up, display only
        $this->assertSame('$0.46734375', Nanodollars::format(467_343_750, 8));
    }
}
