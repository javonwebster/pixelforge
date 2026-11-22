<?php

namespace App\Http\Requests;

/**
 * The live price panel asks while the user is still typing, so an empty
 * prompt is allowed here: it prices the fixed parts (output, reasoning,
 * reference images) with no text tokens.
 */
class QuoteRequest extends GenerationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['prompt' => ['nullable', 'string', 'max:'.self::MAX_PROMPT_CHARACTERS]] + parent::rules();
    }
}
