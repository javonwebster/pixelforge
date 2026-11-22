<?php

namespace App\Services\Pricing;

use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Current Pixelforge rates, for estimates made before a generation exists.
 *
 * Pixelforge says to read rates from /v1/pricing rather than hard-code
 * them. They're cached for an hour, because the price panel asks on every
 * (debounced) keystroke. If Pixelforge can't be reached, the documented
 * rates are used and the next try is a minute later.
 *
 * Only estimates and holds use these. Actual costs always use the rates
 * stored on the generation, which Pixelforge fixes when it's created.
 */
class PricingCatalog
{
    public const string CACHE_KEY = 'pixelforge:pricing:rates';

    public const int CACHE_SECONDS = 3600;

    public const int FALLBACK_CACHE_SECONDS = 60;

    public function __construct(private readonly PixelforgeClient $client) {}

    public function currentRates(): Rates
    {
        /** @var array<string, string>|null $cached */
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return Rates::fromArray($cached);
        }

        try {
            $rates = $this->fetch();
            $seconds = self::CACHE_SECONDS;
        } catch (ConnectionException|RequestException|UnexpectedValueException|InvalidArgumentException $e) {
            Log::warning('Pixelforge pricing unavailable, using documented rates.', ['error' => $e->getMessage()]);
            $rates = Rates::documented();
            $seconds = self::FALLBACK_CACHE_SECONDS;
        }

        // Cached as plain strings, never as objects or floats.
        Cache::put(self::CACHE_KEY, $rates->toArray(), $seconds);

        return $rates;
    }

    private function fetch(): Rates
    {
        $body = $this->client->pricing();

        // Refuse anything that isn't the documented shape rather than
        // misread, say, cents or per-1K rates as dollars per 1M.
        if (($body['currency'] ?? null) !== 'usd' || ($body['unit'] ?? null) !== 'per_1m_tokens' || ! is_array($body['rates'] ?? null)) {
            throw new UnexpectedValueException('Unexpected /v1/pricing response shape.');
        }

        return Rates::fromArray($body['rates']);
    }
}
