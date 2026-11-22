<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Enums\LedgerType;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Pixelforge\OutboundThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private const int START = 5_000_000_000;

    private User $user;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pixelforge.key' => 'pf_test_key']);
    }

    /** Submit through the real path, then fake Pixelforge's answers. */
    private function given(mixed $generationResponses): void
    {
        $this->fakePixelforge($generationResponses);
        $this->user = $this->userWithCredit(self::START);
        $this->generation = $this->submitGeneration($this->user);
    }

    private function runJob(): SubmitGeneration
    {
        $job = (new SubmitGeneration($this->generation->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }

    /** @param  array<string, mixed>  $body */
    private function error(int $status, string $code, array $headers = [], array $body = []): mixed
    {
        return Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => "Pixelforge says {$code}."]] + $body, $status, $headers);
    }

    private function fresh(): Generation
    {
        return $this->generation->fresh() ?? $this->fail('Generation disappeared.');
    }

    private function balance(): int
    {
        return app(LedgerService::class)->available($this->user);
    }

    private function generationRequests(): int
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/v1/generations'))->count();
    }

    public function test_accepted_generation_is_queued_with_its_pixelforge_id_and_rates(): void
    {
        $this->given(fn () => Http::response($this->pixelforgeObject($this->generation), 202));

        $this->runJob()->assertNotReleased();

        $fresh = $this->fresh();
        $this->assertSame(GenerationStatus::Queued, $fresh->status);
        $this->assertStringStartsWith('gen_', (string) $fresh->pixelforge_id);
        $this->assertEquals($this->documentedRates(), $fresh->rates);   // jsonb reorders keys
        $this->assertSame('2026-11-22 14:03:11', $fresh->submitted_at?->format('Y-m-d H:i:s'));
        $this->assertSame(self::START - self::MAX_PRICE, $this->balance());   // still held

        Http::assertSent(function (Request $request) {
            return str_ends_with($request->url(), '/v1/generations')
                && $request->header('Authorization') === ['Bearer pf_test_key']
                && $request->header('Idempotency-Key') === [$this->generation->id]
                && $request->body() === json_encode([
                    'prompt' => $this->generation->prompt,
                    'size' => '1024x1024',
                    'quality' => 'medium',
                    'metadata' => ['local_id' => $this->generation->id],
                ]);
        });
    }

    public function test_overloaded_then_accepted_sends_the_same_request_twice(): void
    {
        $calls = 0;
        $this->given(function () use (&$calls) {
            return ++$calls === 1
                ? $this->error(503, 'overloaded', ['Retry-After' => '2'])
                : Http::response($this->pixelforgeObject($this->generation), 202, ['Idempotent-Replayed' => 'false']);
        });

        $this->runJob()->assertReleased(delay: 2);
        $this->assertSame('overloaded', $this->fresh()->last_submit_error);
        $this->assertSame(GenerationStatus::Pending, $this->fresh()->status);

        $this->runJob()->assertNotReleased();
        $this->assertSame(GenerationStatus::Queued, $this->fresh()->status);

        $requests = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/v1/generations'))
            ->map(fn (array $pair) => [$pair[0]->header('Idempotency-Key'), $pair[0]->body()])
            ->values();
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0], $requests[1]);
        $this->assertSame(1, Generation::query()->count());
    }

    public function test_concurrency_limit_waits_for_retry_after(): void
    {
        $this->given($this->error(429, 'concurrency_limit_exceeded', ['Retry-After' => '5']));

        $this->runJob()->assertReleased(delay: 5);

        $this->assertSame(GenerationStatus::Pending, $this->fresh()->status);
        $this->assertSame('concurrency_limit_exceeded', $this->fresh()->last_submit_error);
        $this->assertSame(self::START - self::MAX_PRICE, $this->balance());
    }

    public function test_rate_limit_waits_for_retry_after(): void
    {
        $this->given($this->error(429, 'rate_limit_exceeded', ['Retry-After' => '17']));

        $this->runJob()->assertReleased(delay: 17);
    }

    public function test_waits_locally_when_three_are_already_in_flight(): void
    {
        $this->given(Http::response([], 500));
        Generation::factory()->count(2)->status(GenerationStatus::Queued)->create();
        Generation::factory()->status(GenerationStatus::Processing)->create();

        $this->runJob()->assertReleased(delay: 5);

        $this->assertSame(0, $this->generationRequests());
        $this->assertSame('concurrency_limit_exceeded', $this->fresh()->last_submit_error);
    }

    public function test_waits_locally_when_the_request_budget_is_used_up(): void
    {
        $this->given(Http::response([], 500));
        $throttle = app(OutboundThrottle::class);
        for ($i = 0; $i < OutboundThrottle::LIMIT; $i++) {
            $throttle->reserve();
        }

        $this->runJob()->assertReleased(delay: 60);

        $this->assertSame(0, $this->generationRequests());
    }

    public function test_content_policy_violation_is_rejected_and_free(): void
    {
        $this->given($this->error(400, 'content_policy_violation'));

        $this->runJob()->assertNotReleased();

        $fresh = $this->fresh();
        $this->assertSame(GenerationStatus::Rejected, $fresh->status);
        $this->assertSame('content_policy_violation', $fresh->error_code);
        $this->assertSame(0, $fresh->charge_nanos);
        $this->assertSame(0, $fresh->provider_cost_nanos);
        $this->assertSame(self::START, $this->balance());
        $this->assertSame(0, LedgerEntry::query()->where('type', LedgerType::Charge)->count());
    }

    public function test_daily_limit_is_rejected_and_free(): void
    {
        $this->given($this->error(429, 'daily_limit_exceeded'));

        $this->runJob()->assertNotReleased();

        $this->assertSame(GenerationStatus::Rejected, $this->fresh()->status);
        $this->assertSame('daily_limit_exceeded', $this->fresh()->error_code);
        $this->assertSame(self::START, $this->balance());
    }

    public function test_validation_failure_is_rejected_and_logged_as_a_bug(): void
    {
        Log::spy();
        $this->given($this->error(422, 'validation_failed', body: ['param' => 'size']));

        $this->runJob()->assertNotReleased();

        $this->assertSame(GenerationStatus::Rejected, $this->fresh()->status);
        $this->assertSame(self::START, $this->balance());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_bad_api_key_is_rejected_and_logged(): void
    {
        Log::spy();
        $this->given($this->error(401, 'invalid_api_key'));

        $this->runJob();

        $this->assertSame(GenerationStatus::Rejected, $this->fresh()->status);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_idempotency_key_reuse_is_a_submission_failure(): void
    {
        Log::spy();
        $this->given($this->error(409, 'idempotency_key_reused'));

        $this->runJob()->assertNotReleased();

        $this->assertSame(GenerationStatus::SubmissionFailed, $this->fresh()->status);
        $this->assertSame(self::START, $this->balance());
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_timeout_retries_with_backoff(): void
    {
        $this->given(Http::failedConnection());

        $this->runJob()->assertReleased(delay: 2);

        $this->assertSame(GenerationStatus::Pending, $this->fresh()->status);
        $this->assertSame('connection_error', $this->fresh()->last_submit_error);
    }

    public function test_giving_up_after_timeouts_is_a_submission_failure(): void
    {
        $this->given(Http::failedConnection());
        $this->runJob();

        (new SubmitGeneration($this->generation->id))->failed(new MaxAttemptsExceededException);

        $fresh = $this->fresh();
        $this->assertSame(GenerationStatus::SubmissionFailed, $fresh->status);
        $this->assertSame('connection_error', $fresh->error_code);
        $this->assertSame(0, $fresh->charge_nanos);
        $this->assertSame(self::START, $this->balance());
    }

    public function test_giving_up_after_overloads_is_a_rejection(): void
    {
        $this->given($this->error(503, 'overloaded', ['Retry-After' => '2']));
        $this->runJob();

        (new SubmitGeneration($this->generation->id))->failed(new MaxAttemptsExceededException);

        $this->assertSame(GenerationStatus::Rejected, $this->fresh()->status);
        $this->assertSame('overloaded', $this->fresh()->error_code);
        $this->assertSame(self::START, $this->balance());
    }

    public function test_a_local_wait_never_hides_an_earlier_timeout(): void
    {
        // Timed out (outcome unknown), then only waited locally. Giving up
        // must still treat it as possibly created.
        $this->given(Http::failedConnection());
        $this->runJob();
        Generation::factory()->count(3)->status(GenerationStatus::Queued)->create();
        $this->runJob()->assertReleased(delay: 5);

        $this->assertSame('connection_error', $this->fresh()->last_submit_error);

        (new SubmitGeneration($this->generation->id))->failed(new MaxAttemptsExceededException);
        $this->assertSame(GenerationStatus::SubmissionFailed, $this->fresh()->status);
    }

    public function test_replay_of_a_finished_generation_is_billed_once(): void
    {
        // An idempotent replay returns the generation as it is now, which
        // can already be succeeded.
        $this->given(fn () => Http::response($this->pixelforgeObject($this->generation, 'succeeded'), 202, ['Idempotent-Replayed' => 'true']));

        $this->runJob();
        $this->runJob();   // a second copy of the job is harmless too

        $fresh = $this->fresh();
        $this->assertSame(GenerationStatus::Succeeded, $fresh->status);
        $this->assertSame(self::SUCCEEDED_COST, $fresh->provider_cost_nanos);
        $this->assertSame(self::SUCCEEDED_PRICE, $fresh->charge_nanos);
        $this->assertSame(self::START - self::SUCCEEDED_PRICE, $this->balance());
        $this->assertSame(1, $this->generationRequests());
    }

    public function test_does_nothing_if_a_webhook_already_moved_it_on(): void
    {
        $this->given(Http::response([], 500));
        $this->generation->update(['status' => GenerationStatus::Processing]);

        $this->runJob()->assertNotReleased();

        $this->assertSame(0, $this->generationRequests());
    }

    public function test_unrecognised_server_error_retries(): void
    {
        $this->given(Http::response('<html>Bad gateway</html>', 502));

        $this->runJob()->assertReleased(delay: 2);

        $this->assertSame('unexpected_response', $this->fresh()->last_submit_error);
    }
}
