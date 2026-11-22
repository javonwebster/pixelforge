<?php

namespace App\Enums;

/**
 * Pixelforge's documented error codes, plus the outcomes we record
 * ourselves when no response came back.
 *
 * @see https://pixelforge.promptedit.dev/docs
 */
enum PixelforgeErrorCode: string
{
    // Request errors (HTTP responses).
    case InvalidApiKey = 'invalid_api_key';                         // 401
    case ValidationFailed = 'validation_failed';                    // 422
    case ContentPolicyViolation = 'content_policy_violation';       // 400
    case NotFound = 'not_found';                                    // 404
    case IdempotencyKeyReused = 'idempotency_key_reused';           // 409
    case RateLimitExceeded = 'rate_limit_exceeded';                 // 429
    case ConcurrencyLimitExceeded = 'concurrency_limit_exceeded';   // 429
    case DailyLimitExceeded = 'daily_limit_exceeded';               // 429
    case Overloaded = 'overloaded';                                 // 503
    case DateRangeTooLong = 'date_range_too_long';                  // 422

    // Generation errors (a failed generation's `error.code`).
    case GenerationFailed = 'generation_failed';
    case InternalError = 'internal_error';

    // Ours: no usable Pixelforge answer.
    case ConnectionError = 'connection_error';
    case UnexpectedResponse = 'unexpected_response';

    /**
     * A clean refusal: Pixelforge answered and definitely created nothing.
     * If the submit job gives up after one of these, the row is `rejected`;
     * after anything else it's `submission_failed`, because Pixelforge may
     * have created it without our seeing the response.
     */
    public function createdNothing(): bool
    {
        return match ($this) {
            self::InvalidApiKey, self::ValidationFailed, self::ContentPolicyViolation,
            self::RateLimitExceeded, self::ConcurrencyLimitExceeded, self::DailyLimitExceeded,
            self::Overloaded => true,
            default => false,
        };
    }
}
