<?php

namespace Tests\Unit\Pricing;

use App\Services\Pricing\Rates;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RatesTest extends TestCase
{
    public function test_parses_pixelforge_rate_strings_exactly(): void
    {
        $rates = Rates::fromArray([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);

        $this->assertSame('12.50', $rates->reasoning->toString());
        $this->assertSame([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ], $rates->toArray());
    }

    public function test_documented_rates_round_trip(): void
    {
        $this->assertEquals(Rates::documented(), Rates::fromArray(Rates::documented()->toArray()));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badRates(): array
    {
        return [
            'float' => [12.5],
            'negative' => ['-1.00'],
            'not a number' => ['twelve'],
            'exponent' => ['1.25e1'],
            'missing' => [null],
        ];
    }

    #[DataProvider('badRates')]
    public function test_rejects_anything_but_a_decimal_string(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Rates::fromArray([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => $value,
        ]);
    }
}
