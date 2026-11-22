<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/**
 * Token counts for the four billed token types. Either a prediction
 * (estimate or maximum) or Pixelforge's actual `usage` after success.
 */
final readonly class TokenCounts
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {
        foreach ([$inputText, $inputImage, $output, $reasoning] as $count) {
            if ($count < 0) {
                throw new InvalidArgumentException('Token counts cannot be negative.');
            }
        }
    }

    /**
     * From a Pixelforge generation's `usage` object.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromUsage(array $usage): self
    {
        return new self(
            inputText: self::int($usage, 'input_text_tokens'),
            inputImage: self::int($usage, 'input_image_tokens'),
            output: self::int($usage, 'output_tokens'),
            reasoning: self::int($usage, 'reasoning_tokens'),
        );
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private static function int(array $usage, string $key): int
    {
        $value = $usage[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException("Usage field [{$key}] must be an integer.");
        }

        return $value;
    }
}
