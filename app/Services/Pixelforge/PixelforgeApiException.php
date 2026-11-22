<?php

namespace App\Services\Pixelforge;

use App\Enums\PixelforgeErrorCode;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Pixelforge answered with an error. The documented `error.code` is mapped
 * to an enum so callers can match on it; anything unrecognised (a proxy's
 * HTML 502, say) becomes UnexpectedResponse.
 */
final class PixelforgeApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly PixelforgeErrorCode $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error');
        $code = is_array($error) && is_string($error['code'] ?? null)
            ? PixelforgeErrorCode::tryFrom($error['code'])
            : null;
        $message = is_array($error) && is_string($error['message'] ?? null)
            ? $error['message']
            : "Pixelforge returned HTTP {$response->status()}.";

        return new self(
            status: $response->status(),
            errorCode: $code ?? PixelforgeErrorCode::UnexpectedResponse,
            message: $message,
            retryAfter: self::retryAfter($response),
        );
    }

    private static function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return ctype_digit($header) ? (int) $header : null;
    }
}
