<?php

namespace App\Http\Requests;

use App\Enums\Quality;
use App\Enums\Size;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A generation as the user asked for it. The rules mirror Pixelforge's
 * POST /v1/generations validation, so its `422 validation_failed` should
 * never happen in practice: prompt up to 4,000 characters, the three
 * sizes and qualities, and up to 4 http(s) reference image URLs.
 *
 * @see https://pixelforge.promptedit.dev/docs
 */
class GenerationRequest extends FormRequest
{
    public const int MAX_PROMPT_CHARACTERS = 4_000;

    public const int MAX_REFERENCE_IMAGES = 4;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Blank reference image fields are just unused inputs, not errors.
     */
    protected function prepareForValidation(): void
    {
        $urls = $this->input('reference_images');

        if (is_array($urls)) {
            $this->merge(['reference_images' => array_values(array_filter($urls, fn ($url) => is_string($url) && trim($url) !== ''))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Laravel's max counts characters (mb_strlen), as Pixelforge does.
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::enum(Size::class)],
            'quality' => ['required', Rule::enum(Quality::class)],
            'reference_images' => ['nullable', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['string', 'max:2048', 'url:http,https'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'Up to 4 reference images.',
            'reference_images.*.url' => 'Reference images must be http or https links.',
        ];
    }

    public function prompt(): string
    {
        return $this->string('prompt')->toString();
    }

    public function size(): Size
    {
        return $this->enum('size', Size::class) ?? Size::Square;
    }

    public function quality(): Quality
    {
        return $this->enum('quality', Quality::class) ?? Quality::Medium;
    }

    /**
     * @return list<string>
     */
    public function referenceImages(): array
    {
        /** @var list<string> */
        return $this->validated('reference_images') ?? [];
    }
}
