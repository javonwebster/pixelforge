<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/**
 * The only code that talks to the Pixelforge API.
 *
 * Grows in the submit step with bearer auth, the shared outbound throttle
 * (Pixelforge allows 60 requests a minute per account, across all keys)
 * and mapping of error codes to typed exceptions.
 *
 * @see https://pixelforge.promptedit.dev/docs
 */
class PixelforgeClient
{
    /**
     * GET /v1/pricing: current rates and token rules. Needs no
     * authentication, so no key is sent. The docs don't say whether it
     * counts toward the rate limit; PricingCatalog caches it so it's
     * about one call an hour either way.
     *
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function pricing(): array
    {
        $body = $this->request()->get('pricing')->throw()->json();

        if (! is_array($body)) {
            throw new UnexpectedValueException('Pixelforge /v1/pricing did not return a JSON object.');
        }

        return $body;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('services.pixelforge.base_url'))
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10);
    }
}
