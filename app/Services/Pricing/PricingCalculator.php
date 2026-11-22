<?php

namespace App\Services\Pricing;

use App\Enums\Quality;
use App\Enums\Size;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * The one place that turns tokens into money: the live estimate on the
 * Generate page, the maximum the balance hold covers, and the final charge
 * after success all come from here.
 *
 * Pixelforge rules it applies (https://pixelforge.promptedit.dev/docs):
 * - Rates are US dollars per 1M tokens, fixed on each generation.
 * - Input text is about one token per 4 Unicode characters, and the real
 *   count is within 15% of ceil(characters / 4) either way.
 * - Each reference image is billed as exactly 765 input image tokens.
 * - Output tokens are exact for a size and quality; reasoning tokens fall
 *   inside the quality's range.
 * - Failed generations are never billed.
 *
 * Users pay Pixelforge's cost plus 25%. Everything is integer nanodollars.
 */
final class PricingCalculator
{
    /** Price = cost × 125 / 100. */
    public const int MARKUP_PERCENT = 125;

    public const int CHARACTERS_PER_TEXT_TOKEN = 4;

    /** The text token count can come in up to 15% above the estimate. */
    public const int TEXT_VARIANCE_PERCENT = 15;

    public const int TOKENS_PER_REFERENCE_IMAGE = 765;

    /** 1e9 nanodollars per dollar ÷ 1e6 tokens per rate unit. */
    private const int NANOS_PER_RATE_UNIT_TOKEN = 1_000;

    public function quote(string $prompt, Size $size, Quality $quality, int $referenceImages, Rates $rates): Quote
    {
        $estimated = $this->estimatedTokens($prompt, $size, $quality, $referenceImages);
        $maximum = $this->maximumTokens($prompt, $size, $quality, $referenceImages);

        $estimatedCost = $this->cost($estimated, $rates);
        $maximumCost = $this->cost($maximum, $rates);

        return new Quote(
            estimatedTokens: $estimated,
            maximumTokens: $maximum,
            estimatedCost: $estimatedCost,
            maximumCost: $maximumCost,
            estimatedPrice: $this->price($estimatedCost),
            maximumPrice: $this->price($maximumCost),
        );
    }

    /**
     * Most likely token counts: the text estimate as documented, and the
     * midpoint of the quality's reasoning range.
     */
    public function estimatedTokens(string $prompt, Size $size, Quality $quality, int $referenceImages): TokenCounts
    {
        $min = $quality->minReasoningTokens();
        $max = $quality->maxReasoningTokens();

        return new TokenCounts(
            inputText: $this->estimatedTextTokens($prompt),
            inputImage: $this->imageTokens($referenceImages),
            output: $quality->outputTokens($size),
            reasoning: self::ceilDiv($min + $max, 2),
        );
    }

    /**
     * The most Pixelforge can bill: text 15% over the estimate, the top of
     * the reasoning range. Matches the docs' own maximum-cost example.
     */
    public function maximumTokens(string $prompt, Size $size, Quality $quality, int $referenceImages): TokenCounts
    {
        $text = $this->estimatedTextTokens($prompt);

        return new TokenCounts(
            inputText: self::ceilDiv($text * (100 + self::TEXT_VARIANCE_PERCENT), 100),
            inputImage: $this->imageTokens($referenceImages),
            output: $quality->outputTokens($size),
            reasoning: $quality->maxReasoningTokens(),
        );
    }

    /**
     * Pixelforge's cost in nanodollars: Σ tokens × rate / 1,000,000 USD.
     *
     * The whole sum is computed in exact decimals and converted once at
     * the end, so a rate with more decimals still works as long as the
     * total is a whole number of nanodollars. If it isn't, this throws
     * rather than round.
     *
     * @throws InexactCostException
     */
    public function cost(TokenCounts $tokens, Rates $rates): int
    {
        $usdPerMillion = BigDecimal::zero()
            ->plus($rates->inputText->multipliedBy($tokens->inputText))
            ->plus($rates->inputImage->multipliedBy($tokens->inputImage))
            ->plus($rates->output->multipliedBy($tokens->output))
            ->plus($rates->reasoning->multipliedBy($tokens->reasoning));

        try {
            return $usdPerMillion->multipliedBy(self::NANOS_PER_RATE_UNIT_TOKEN)->toBigInteger()->toInt();
        } catch (RoundingNecessaryException $e) {
            throw new InexactCostException(
                "Cost [{$usdPerMillion} USD per 1M tokens] is not a whole number of nanodollars.",
                previous: $e,
            );
        }
    }

    /**
     * Cost plus the 25% markup, in nanodollars.
     *
     * With today's rates every cost is a multiple of 2,500 nanodollars, so
     * this is always exact. If rates change it rounds half up to the
     * nanodollar, so the price is off by at most half a nanodollar.
     */
    public function price(int $costNanos): int
    {
        if ($costNanos < 0) {
            throw new InvalidArgumentException('Cost cannot be negative.');
        }

        return BigDecimal::of($costNanos)
            ->multipliedBy(self::MARKUP_PERCENT)
            ->dividedBy(100, 0, RoundingMode::HalfUp)
            ->toInt();
    }

    /**
     * ceil(characters / 4), counting Unicode characters, not bytes.
     */
    private function estimatedTextTokens(string $prompt): int
    {
        return self::ceilDiv(mb_strlen($prompt, 'UTF-8'), self::CHARACTERS_PER_TEXT_TOKEN);
    }

    private function imageTokens(int $referenceImages): int
    {
        if ($referenceImages < 0) {
            throw new InvalidArgumentException('Reference image count cannot be negative.');
        }

        return $referenceImages * self::TOKENS_PER_REFERENCE_IMAGE;
    }

    /** Integer ceiling division for non-negative integers. */
    private static function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
