<?php

namespace App\Services\Pricing;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Pixelforge rates in US dollars per 1M tokens, kept as exact decimals.
 *
 * Pixelforge sends rates as strings ("12.50"). They are parsed straight
 * into BigDecimal and never pass through a float. A generation's rates
 * are fixed when it is created, so actual costs always use the rates
 * stored on that generation, not current pricing.
 *
 * @see https://pixelforge.promptedit.dev/docs
 */
final readonly class Rates
{
    public const array KEYS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(
        public BigDecimal $inputText,
        public BigDecimal $inputImage,
        public BigDecimal $output,
        public BigDecimal $reasoning,
    ) {
        foreach ([$inputText, $inputImage, $output, $reasoning] as $rate) {
            if ($rate->isNegative()) {
                throw new InvalidArgumentException('Rates cannot be negative.');
            }
        }
    }

    /**
     * From a Pixelforge `rates` object, on a generation or from /v1/pricing.
     *
     * @param  array<string, mixed>  $rates
     */
    public static function fromArray(array $rates): self
    {
        return new self(
            inputText: self::decimal($rates, 'input_text_tokens'),
            inputImage: self::decimal($rates, 'input_image_tokens'),
            output: self::decimal($rates, 'output_tokens'),
            reasoning: self::decimal($rates, 'reasoning_tokens'),
        );
    }

    /**
     * The documented rates, used only when /v1/pricing can't be reached.
     */
    public static function documented(): self
    {
        return self::fromArray([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);
    }

    /**
     * Same shape and string format Pixelforge uses, for storing as jsonb.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputText->toString(),
            'input_image_tokens' => $this->inputImage->toString(),
            'output_tokens' => $this->output->toString(),
            'reasoning_tokens' => $this->reasoning->toString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $rates
     */
    private static function decimal(array $rates, string $key): BigDecimal
    {
        $value = $rates[$key] ?? null;

        // Strings and integers only. A float here would already have lost
        // precision when the JSON was decoded.
        if (is_int($value)) {
            return BigDecimal::of($value);
        }

        if (! is_string($value) || preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Rate [{$key}] must be a decimal string.");
        }

        return BigDecimal::of($value);
    }
}
