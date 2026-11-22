<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileGenerations;
use App\Enums\GenerationStatus;
use App\Enums\LedgerType;
use App\Enums\PixelforgeErrorCode;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\GenerationUpdater;
use App\Services\LedgerService;
use App\Services\Pixelforge\OutboundThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class ReconcileTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private const int START = 5_000_000_000;

    private User $user;

    /** @var array<string, array<string, mixed>> Pixelforge's view, by Pixelforge id */
    private array $remote = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pixelforge.key' => 'pf_test_key']);
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/v1/pricing') {
                return Http::response(['object' => 'pricing', 'currency' => 'usd', 'unit' => 'per_1m_tokens', 'rates' => $this->documentedRates()]);
            }

            if ($path === '/v1/generations') {
                return Http::response(['object' => 'list', 'data' => array_values($this->remote), 'has_more' => false, 'next_cursor' => null]);
            }

            $id = basename($path);

            return isset($this->remote[$id])
                ? Http::response($this->remote[$id])
                : Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'not_found', 'message' => 'No such generation.']], 404);
        });

        $this->user = $this->userWithCredit(self::START);
    }

    /** A generation Pixelforge accepted, now quiet for $seconds. */
    private function acceptedGeneration(string $status = 'processing', int $quietFor = 120): Generation
    {
        $generation = $this->submitGeneration($this->user);
        app(GenerationUpdater::class)->apply($this->pixelforgeObject($generation, $status));
        Generation::query()->whereKey($generation->id)->update(['updated_at' => now()->subSeconds($quietFor)]);

        return $generation->fresh() ?? $this->fail('Generation disappeared.');
    }

    private function pixelforgeSays(Generation $generation, string $status): void
    {
        $object = $this->pixelforgeObject($generation, $status);
        $this->remote[$object['id']] = $object;
    }

    private function apiCalls(string $pathSuffix): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), $pathSuffix))->count();
    }

    private function charges(Generation $generation): int
    {
        return LedgerEntry::query()->where('generation_id', $generation->id)->where('type', LedgerType::Charge)->count();
    }

    public function test_stuck_processing_generation_is_polled_and_charged_once(): void
    {
        $generation = $this->acceptedGeneration();
        $this->pixelforgeSays($generation, 'succeeded');

        $this->artisan('pixelforge:reconcile')->assertSuccessful();
        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()?->status);
        $this->assertSame(1, $this->charges($generation));
        $this->assertSame(self::START - self::SUCCEEDED_PRICE, app(LedgerService::class)->available($this->user));
    }

    public function test_a_poll_then_the_late_webhook_still_charge_once(): void
    {
        $generation = $this->acceptedGeneration();
        $this->pixelforgeSays($generation, 'succeeded');
        $this->artisan('pixelforge:reconcile');

        $this->postWebhook($this->webhookEvent($generation, 'succeeded'))->assertOk();

        $this->assertSame(1, $this->charges($generation));
    }

    public function test_recently_updated_generations_are_left_to_their_webhooks(): void
    {
        $generation = $this->acceptedGeneration(quietFor: 30);
        $this->pixelforgeSays($generation, 'succeeded');

        $this->artisan('pixelforge:reconcile');

        $this->assertSame(GenerationStatus::Processing, $generation->fresh()?->status);
        $this->assertSame(0, $this->apiCalls('/v1/generations/'));
    }

    public function test_a_poll_with_no_news_waits_another_minute(): void
    {
        $generation = $this->acceptedGeneration();
        $this->pixelforgeSays($generation, 'processing');

        $this->artisan('pixelforge:reconcile');
        $this->artisan('pixelforge:reconcile');

        $this->assertSame(1, $this->apiCalls('/v1/generations/'));
    }

    public function test_polls_are_capped_per_run(): void
    {
        for ($i = 0; $i < ReconcileGenerations::MAX_POLLS + 3; $i++) {
            $this->pixelforgeSays($this->acceptedGeneration(), 'processing');
        }

        $this->artisan('pixelforge:reconcile');

        $this->assertSame(ReconcileGenerations::MAX_POLLS, $this->apiCalls('/v1/generations/'));
    }

    public function test_submission_failed_found_in_the_list_moves_forward_and_is_charged(): void
    {
        // The submit timed out and the job gave up; Pixelforge had it all along.
        $generation = $this->submitGeneration($this->user);
        app(GenerationUpdater::class)->markSubmissionFailed($generation, PixelforgeErrorCode::ConnectionError, 'Gave up.');
        $this->assertSame(self::START, app(LedgerService::class)->available($this->user));
        $this->pixelforgeSays($generation, 'succeeded');

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        $fresh = $generation->fresh();
        $this->assertSame(GenerationStatus::Succeeded, $fresh?->status);
        $this->assertNotNull($fresh?->pixelforge_id);
        $this->assertSame(1, $this->charges($generation));
        $this->assertSame(self::START - self::SUCCEEDED_PRICE, app(LedgerService::class)->available($this->user));
        $this->assertSame(1, $this->apiCalls('/v1/generations?'));
    }

    public function test_submission_failed_missing_from_the_list_stays_free(): void
    {
        $generation = $this->submitGeneration($this->user);
        app(GenerationUpdater::class)->markSubmissionFailed($generation, PixelforgeErrorCode::ConnectionError, 'Gave up.');

        $this->artisan('pixelforge:reconcile');

        $this->assertSame(GenerationStatus::SubmissionFailed, $generation->fresh()?->status);
        $this->assertSame(self::START, app(LedgerService::class)->available($this->user));
    }

    public function test_submission_failures_older_than_a_day_are_no_longer_searched(): void
    {
        $generation = $this->submitGeneration($this->user);
        app(GenerationUpdater::class)->markSubmissionFailed($generation, PixelforgeErrorCode::ConnectionError, 'Gave up.');
        Generation::query()->whereKey($generation->id)->update(['created_at' => now()->subHours(25)]);
        $this->pixelforgeSays($generation, 'succeeded');

        $this->artisan('pixelforge:reconcile');

        $this->assertSame(GenerationStatus::SubmissionFailed, $generation->fresh()?->status);
        $this->assertSame(0, $this->apiCalls('/v1/generations?'));
    }

    public function test_no_list_call_without_submission_failures(): void
    {
        $this->artisan('pixelforge:reconcile');

        $this->assertSame(0, $this->apiCalls('/v1/generations'));
    }

    public function test_an_unmatched_webhook_event_is_applied_once_the_generation_is_known(): void
    {
        // The event names a Pixelforge id with no metadata, before our row
        // has that id (its submit response hasn't been saved yet).
        $generation = $this->submitGeneration($this->user);
        $event = $this->webhookEvent($generation, 'succeeded', ['metadata' => []]);
        $this->postWebhook($event)->assertJson(['status' => 'unmatched']);

        // The submit response lands.
        app(GenerationUpdater::class)->apply($this->pixelforgeObject($generation, 'queued'));

        $this->artisan('pixelforge:reconcile');

        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()?->status);
        $this->assertNotNull(WebhookEvent::query()->sole()->processed_at);
        $this->assertSame(1, $this->charges($generation));
    }

    public function test_stops_quietly_when_the_request_budget_is_used_up(): void
    {
        $generation = $this->acceptedGeneration();
        $this->pixelforgeSays($generation, 'succeeded');
        $throttle = app(OutboundThrottle::class);
        for ($i = 0; $i < OutboundThrottle::LIMIT; $i++) {
            $throttle->reserve();
        }

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        $this->assertSame(GenerationStatus::Processing, $generation->fresh()?->status);
        $this->assertSame(0, $this->apiCalls('/v1/generations'));
    }

    public function test_a_failed_poll_does_not_stop_the_others(): void
    {
        $missing = $this->acceptedGeneration(quietFor: 200);   // 404 at Pixelforge
        $found = $this->acceptedGeneration();
        $this->pixelforgeSays($found, 'succeeded');

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        $this->assertSame(GenerationStatus::Processing, $missing->fresh()?->status);
        $this->assertSame(GenerationStatus::Succeeded, $found->fresh()?->status);
    }

    public function test_runs_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('pixelforge:reconcile');
    }
}
