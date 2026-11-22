<?php

namespace App\Services\Pixelforge;

use App\Enums\PixelforgeErrorCode;
use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/**
 * The only code that talks to the Pixelforge API: bearer auth, the shared
 * outbound throttle, and Pixelforge's error codes mapped to
 * PixelforgeApiException. Connection failures and timeouts surface as
 * Laravel's ConnectionException, because their outcome is unknown.
 *
 * @see https://pixelforge.promptedit.dev/docs
 */
class PixelforgeClient
{
    public function __construct(private readonly OutboundThrottle $throttle) {}

    /**
     * POST /v1/generations.
     *
     * The local UUID is both the Idempotency-Key and metadata.local_id, and
     * the body is built from the stored row the same way every time, so a
     * retry is byte-identical: Pixelforge replays the original generation
     * (as it is now) instead of creating a second one, and can never answer
     * 409 idempotency_key_reused.
     *
     * @return array<string, mixed> the generation object
     *
     * @throws ThrottledLocallyException nothing was sent
     * @throws PixelforgeApiException Pixelforge refused
     * @throws ConnectionException outcome unknown
     */
    public function createGeneration(Generation $generation): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request
            ->withHeaders(['Idempotency-Key' => $generation->id])
            ->withBody(self::encode(self::submitBody($generation)), 'application/json')
            ->post('generations'));

        return $this->generationObject($response);
    }

    /**
     * GET /v1/pricing: current rates and token rules. Needs no
     * authentication, so no key is sent and the throttle isn't involved.
     * The docs don't say whether it counts toward the rate limit;
     * PricingCatalog caches it so it's about one call an hour either way.
     *
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function pricing(): array
    {
        $body = $this->baseRequest()->get('pricing')->throw()->json();

        if (! is_array($body)) {
            throw new UnexpectedValueException('Pixelforge /v1/pricing did not return a JSON object.');
        }

        return $body;
    }

    /**
     * The POST body, from the stored row only, keys always in this order.
     *
     * @return array<string, mixed>
     */
    public static function submitBody(Generation $generation): array
    {
        $body = [
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
        ];

        if ($generation->reference_images !== []) {
            $body['reference_images'] = $generation->reference_images;
        }

        $body['metadata'] = ['local_id' => $generation->id];

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function encode(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Every authenticated call goes through here: throttle, send, learn
     * from the rate-limit headers, map errors.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        $wait = $this->throttle->reserve();

        if ($wait > 0) {
            throw new ThrottledLocallyException($wait);
        }

        $response = $call($this->baseRequest()->withToken((string) config('services.pixelforge.key')));

        $this->throttle->observe($response);

        if ($response->failed()) {
            throw PixelforgeApiException::fromResponse($response);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function generationObject(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body) || ($body['object'] ?? null) !== 'generation' || ! is_string($body['id'] ?? null)) {
            throw new PixelforgeApiException(
                status: $response->status(),
                errorCode: PixelforgeErrorCode::UnexpectedResponse,
                message: 'Pixelforge did not return a generation object.',
            );
        }

        return $body;
    }

    private function baseRequest(): PendingRequest
    {
        return Http::baseUrl((string) config('services.pixelforge.base_url'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20);
    }
}
