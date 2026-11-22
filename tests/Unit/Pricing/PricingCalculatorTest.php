<?php

namespace Tests\Unit\Pricing;

use App\Enums\Quality;
use App\Enums\Size;
use App\Services\Pricing\InexactCostException;
use App\Services\Pricing\PricingCalculator;
use App\Services\Pricing\Rates;
use App\Services\Pricing\TokenCounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The spec's worked examples, all checked by hand against the docs.
 * Amounts are nanodollars: 1 USD = 1,000,000,000.
 */
class PricingCalculatorTest extends TestCase
{
    private PricingCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new PricingCalculator;
    }

    public function test_matches_the_docs_maximum_cost_example(): void
    {
        // High, 1536x1024, 400-character prompt, 2 reference images.
        $quote = $this->calculator->quote(str_repeat('a', 400), Size::Landscape, Quality::High, 2, Rates::documented());

        $this->assertEquals(new TokenCounts(inputText: 115, inputImage: 1_530, output: 6_450, reasoning: 8_000), $quote->maximumTokens);
        $this->assertSame(373_875_000, $quote->maximumCost);      // $0.373875, the docs' figure
        $this->assertSame(467_343_750, $quote->maximumPrice);     // $0.46734375
    }

    public function test_estimate_for_medium_square_prompt(): void
    {
        $quote = $this->calculator->quote(str_repeat('a', 200), Size::Square, Quality::Medium, 0, Rates::documented());

        // 50 text tokens, 1,100 output, reasoning midpoint of 200–2,000.
        $this->assertEquals(new TokenCounts(inputText: 50, inputImage: 0, output: 1_100, reasoning: 1_100), $quote->estimatedTokens);
        $this->assertSame(58_000_000, $quote->estimatedCost);     // $0.058
        $this->assertSame(72_500_000, $quote->estimatedPrice);    // $0.0725
    }

    public function test_maximum_for_medium_square_prompt(): void
    {
        $quote = $this->calculator->quote(str_repeat('a', 200), Size::Square, Quality::Medium, 0, Rates::documented());

        // ceil(50 × 1.15) = 58 text tokens, top of the reasoning range.
        $this->assertEquals(new TokenCounts(inputText: 58, inputImage: 0, output: 1_100, reasoning: 2_000), $quote->maximumTokens);
        $this->assertSame(69_290_000, $quote->maximumCost);       // $0.06929
        $this->assertSame(86_612_500, $quote->maximumPrice);      // $0.0866125
    }

    public function test_failed_generation_costs_nothing(): void
    {
        // Failed generations have no usage and are never billed.
        $cost = $this->calculator->cost(new TokenCounts(0, 0, 0, 0), Rates::documented());

        $this->assertSame(0, $cost);
        $this->assertSame(0, $this->calculator->price($cost));
    }

    public function test_actual_cost_uses_reported_usage(): void
    {
        // The usage figures from the docs' succeeded generation example.
        $usage = TokenCounts::fromUsage([
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6_450,
            'reasoning_tokens' => 3_184,
            'total_tokens' => 10_411,
        ]);

        // 12×5,000 + 765×10,000 + 6,450×40,000 + 3,184×12,500 nanodollars.
        $this->assertSame(305_510_000, $this->calculator->cost($usage, Rates::documented()));
    }

    public function test_actual_cost_uses_the_generations_own_rates(): void
    {
        $rates = Rates::fromArray([
            'input_text_tokens' => '6.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '50.00',
            'reasoning_tokens' => '12.50',
        ]);

        $cost = $this->calculator->cost(new TokenCounts(100, 0, 1_000, 1_000), $rates);

        // 100×6,000 + 1,000×50,000 + 1,000×12,500
        $this->assertSame(63_100_000, $cost);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function markupCases(): array
    {
        return [
            'remainder .25 rounds down' => [1, 1],     // 1.25
            'remainder .5 rounds up' => [2, 3],        // 2.5
            'remainder .75 rounds up' => [3, 4],       // 3.75
            'exact' => [4, 5],
            'zero' => [0, 0],
        ];
    }

    #[DataProvider('markupCases')]
    public function test_markup_rounds_half_up_to_the_nanodollar(int $cost, int $price): void
    {
        $this->assertSame($price, $this->calculator->price($cost));
    }

    public function test_half_up_rounding_through_a_synthetic_rate(): void
    {
        // $0.002 per 1M tokens: one token costs 2 nanodollars, so the
        // marked-up price is 2.5, which rounds half up to 3.
        $rates = Rates::fromArray([
            'input_text_tokens' => '0.002',
            'input_image_tokens' => '0',
            'output_tokens' => '0',
            'reasoning_tokens' => '0',
        ]);

        $cost = $this->calculator->cost(new TokenCounts(1, 0, 0, 0), $rates);

        $this->assertSame(2, $cost);
        $this->assertSame(3, $this->calculator->price($cost));
    }

    public function test_cost_that_is_not_a_whole_nanodollar_throws(): void
    {
        // $12.5001 per 1M × 1 token = 12,500.1 nanodollars.
        $rates = Rates::fromArray([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.5001',
        ]);

        $this->expectException(InexactCostException::class);

        $this->calculator->cost(new TokenCounts(0, 0, 0, 1), $rates);
    }

    public function test_finer_rate_is_fine_when_the_total_is_exact(): void
    {
        // Same rate, 10 tokens: 125,001 nanodollars exactly. The sum is
        // converted once, at the end, so this doesn't throw.
        $rates = Rates::fromArray([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.5001',
        ]);

        $this->assertSame(125_001, $this->calculator->cost(new TokenCounts(0, 0, 0, 10), $rates));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function unicodePrompts(): array
    {
        return [
            'accents: 8 characters, 16 bytes' => ['éèêëàâäç', 2],
            'emoji with variation selector: 4 characters, 15 bytes' => ['🎨🖌️🖼', 1],
            'Japanese: 10 characters, 30 bytes' => ['猫が窓辺で眠っている', 3],
            'mixed: 6 characters, 9 bytes' => ['café ☕', 2],
            'empty' => ['', 0],
        ];
    }

    #[DataProvider('unicodePrompts')]
    public function test_text_tokens_count_characters_not_bytes(string $prompt, int $tokens): void
    {
        $estimated = $this->calculator->estimatedTokens($prompt, Size::Square, Quality::Low, 0);

        $this->assertSame($tokens, $estimated->inputText);
    }

    public function test_reference_images_are_765_tokens_each(): void
    {
        foreach ([0, 1, 4] as $count) {
            $tokens = $this->calculator->estimatedTokens('a cat', Size::Square, Quality::Low, $count);

            $this->assertSame(765 * $count, $tokens->inputImage);
        }
    }

    /**
     * @return array<string, array{Quality, Size, int}>
     */
    public static function outputTokenTable(): array
    {
        return [
            'low square' => [Quality::Low, Size::Square, 280],
            'low landscape' => [Quality::Low, Size::Landscape, 420],
            'low portrait' => [Quality::Low, Size::Portrait, 420],
            'medium square' => [Quality::Medium, Size::Square, 1_100],
            'medium landscape' => [Quality::Medium, Size::Landscape, 1_650],
            'medium portrait' => [Quality::Medium, Size::Portrait, 1_650],
            'high square' => [Quality::High, Size::Square, 4_300],
            'high landscape' => [Quality::High, Size::Landscape, 6_450],
            'high portrait' => [Quality::High, Size::Portrait, 6_450],
        ];
    }

    #[DataProvider('outputTokenTable')]
    public function test_output_tokens_follow_the_documented_table(Quality $quality, Size $size, int $tokens): void
    {
        $this->assertSame($tokens, $this->calculator->estimatedTokens('a', $size, $quality, 0)->output);
        $this->assertSame($tokens, $this->calculator->maximumTokens('a', $size, $quality, 0)->output);
    }

    public function test_reasoning_estimate_is_the_midpoint_and_maximum_the_top(): void
    {
        $this->assertSame(250, $this->calculator->estimatedTokens('a', Size::Square, Quality::Low, 0)->reasoning);
        $this->assertSame(500, $this->calculator->maximumTokens('a', Size::Square, Quality::Low, 0)->reasoning);
        $this->assertSame(4_500, $this->calculator->estimatedTokens('a', Size::Square, Quality::High, 0)->reasoning);
        $this->assertSame(8_000, $this->calculator->maximumTokens('a', Size::Square, Quality::High, 0)->reasoning);
    }

    public function test_maximum_is_never_below_the_estimate(): void
    {
        foreach (Quality::cases() as $quality) {
            foreach (Size::cases() as $size) {
                foreach ([1, 3, 399, 4_000] as $length) {
                    $quote = $this->calculator->quote(str_repeat('x', $length), $size, $quality, 4, Rates::documented());

                    $this->assertGreaterThanOrEqual($quote->estimatedPrice, $quote->maximumPrice);
                }
            }
        }
    }
}
