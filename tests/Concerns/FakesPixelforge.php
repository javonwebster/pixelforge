<?php

namespace Tests\Concerns;

use App\Actions\CreateGeneration;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Helpers for tests that talk to a faked Pixelforge. Response bodies follow
 * the shapes in the API docs.
 */
trait FakesPixelforge
{
    /**
     * @param  array<int, mixed>|mixed  $generations  response(s) for POST /v1/generations
     */
    protected function fakePixelforge(mixed $generations = null): void
    {
        Http::fake([
            '*/v1/pricing' => Http::response([
                'object' => 'pricing',
                'currency' => 'usd',
                'unit' => 'per_1m_tokens',
                'rates' => $this->documentedRates(),
            ]),
            '*/v1/generations' => $generations ?? Http::response([], 500),
        ]);
    }

    /** @return array<string, string> */
    protected function documentedRates(): array
    {
        return [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
    }

    protected function userWithCredit(int $nanos): User
    {
        $user = User::factory()->create();

        if ($nanos > 0) {
            app(LedgerService::class)->purchase($user, $nanos);
        }

        return $user;
    }

    /**
     * Through the real submit path (hold included), without running the
     * job: medium, 1024x1024, 200 characters, so max price 86,612,500.
     */
    protected function submitGeneration(User $user, ?string $prompt = null): Generation
    {
        Queue::fake();

        return app(CreateGeneration::class)->handle($user, $prompt ?? str_repeat('a', 200), Size::Square, Quality::Medium);
    }

    /**
     * A Pixelforge generation object for a local generation.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function pixelforgeObject(Generation $generation, string $status = 'queued', array $overrides = []): array
    {
        $object = [
            'id' => 'gen_'.str_replace('-', '', $generation->id),
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'metadata' => ['local_id' => $generation->id],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => $this->documentedRates(),
            'error' => null,
            'created_at' => '2026-11-22T14:03:11Z',
            'started_at' => null,
            'completed_at' => null,
        ];

        if ($status === 'succeeded') {
            $object['usage'] = [
                'input_text_tokens' => 52,
                'input_image_tokens' => 0,
                'output_tokens' => 1_100,
                'reasoning_tokens' => 1_184,
                'total_tokens' => 2_336,
            ];
            $object['image_url'] = 'https://files.pixelforge.example/img.png';
            $object['image_url_expires_at'] = '2026-11-23T14:03:29Z';
            $object['completed_at'] = '2026-11-22T14:03:29Z';
        }

        if ($status === 'failed') {
            $object['error'] = ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'];
            $object['completed_at'] = '2026-11-22T14:03:29Z';
        }

        return array_replace($object, $overrides);
    }

    protected const string WEBHOOK_SECRET = 'whsec_test_secret';

    /**
     * A webhook event wrapping a generation object, as Pixelforge sends it.
     *
     * @param  array<string, mixed>  $overrides  applied to data.object
     * @return array<string, mixed>
     */
    protected function webhookEvent(Generation $generation, string $status, array $overrides = [], ?string $eventId = null): array
    {
        return [
            'id' => $eventId ?? 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => 'generation.'.$status,
            'created_at' => '2026-11-22T14:03:29Z',
            'data' => ['object' => $this->pixelforgeObject($generation, $status, $overrides)],
        ];
    }

    /**
     * POST a webhook signed the way Pixelforge signs it: HMAC-SHA256 of
     * "<t>.<raw body>" with the whsec_ secret.
     *
     * @param  array<string, mixed>  $event
     * @return TestResponse<Response>
     */
    protected function postWebhook(array $event, ?int $timestamp = null, ?string $secret = null, ?string $body = null): TestResponse
    {
        config(['services.pixelforge.webhook_secret' => self::WEBHOOK_SECRET]);

        $body ??= json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? self::WEBHOOK_SECRET);

        return $this->postSignedBody($body, "t={$timestamp},v1={$signature}");
    }

    /**
     * @return TestResponse<Response>
     */
    protected function postSignedBody(string $body, ?string $signatureHeader): TestResponse
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1'];

        if ($signatureHeader !== null) {
            $headers['HTTP_PIXELFORGE_SIGNATURE'] = $signatureHeader;
        }

        return $this->call('POST', '/webhooks/pixelforge', server: $headers, content: $body);
    }

    /**
     * Actual cost of the 'succeeded' usage above, at documented rates:
     * 52×5,000 + 1,100×40,000 + 1,184×12,500 = 59,060,000 nanodollars,
     * so the price is 73,825,000.
     */
    protected const int SUCCEEDED_COST = 59_060_000;

    protected const int SUCCEEDED_PRICE = 73_825_000;

    protected const int MAX_PRICE = 86_612_500;
}
