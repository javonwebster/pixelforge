<?php

namespace Tests\Feature\Webhooks;

use App\Enums\GenerationStatus;
use App\Enums\LedgerType;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private const int START = 5_000_000_000;

    private User $user;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePixelforge();
        $this->user = $this->userWithCredit(self::START);
        $this->generation = $this->submitGeneration($this->user);
    }

    private function fresh(?Generation $generation = null): Generation
    {
        return ($generation ?? $this->generation)->fresh() ?? $this->fail('Generation disappeared.');
    }

    private function balance(): int
    {
        return app(LedgerService::class)->available($this->user);
    }

    private function entries(Generation $generation, LedgerType $type): int
    {
        return LedgerEntry::query()->where('generation_id', $generation->id)->where('type', $type)->count();
    }

    private function retryOf(Generation $generation): ?Generation
    {
        return Generation::query()->where('retry_of_id', $generation->id)->first();
    }

    public function test_succeeded_event_charges_and_is_recorded(): void
    {
        $event = $this->webhookEvent($this->generation, 'succeeded');

        $this->postWebhook($event)->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame(GenerationStatus::Succeeded, $this->fresh()->status);
        $this->assertSame(self::START - self::SUCCEEDED_PRICE, $this->balance());

        $stored = WebhookEvent::query()->sole();
        $this->assertSame($event['id'], $stored->event_id);
        $this->assertSame('generation.succeeded', $stored->type);
        $this->assertSame(1, $stored->delivery_attempt);
        $this->assertNotNull($stored->processed_at);
    }

    public function test_the_same_event_twice_charges_once(): void
    {
        Log::spy();
        $event = $this->webhookEvent($this->generation, 'succeeded');

        $this->postWebhook($event)->assertOk();
        $this->postWebhook($event)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, $this->entries($this->generation, LedgerType::Charge));
        $this->assertSame(1, $this->entries($this->generation, LedgerType::Release));
        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame(self::START - self::SUCCEEDED_PRICE, $this->balance());
        Log::shouldHaveReceived('info')->with('Duplicate Pixelforge webhook ignored.', \Mockery::on(fn (array $context) => $context['event_id'] === $event['id']))->once();
    }

    public function test_duplicate_deliveries_are_counted_and_shown_in_history(): void
    {
        $event = $this->webhookEvent($this->generation, 'succeeded');

        $this->postWebhook($event)->assertJson(['status' => 'applied']);
        $this->postWebhook($event)->assertJson(['status' => 'duplicate']);
        $this->postWebhook($event)->assertJson(['status' => 'duplicate']);

        $stored = WebhookEvent::query()->sole();
        $this->assertSame(2, $stored->duplicate_deliveries);
        $this->assertNotNull($stored->last_duplicate_at);

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generations.data.0.status', 'succeeded')
                ->where('generations.data.0.duplicateDeliveries', 2));
        $this->assertSame(1, $this->entries($this->generation, LedgerType::Charge));
    }

    public function test_a_generation_without_duplicates_shows_none(): void
    {
        $this->postWebhook($this->webhookEvent($this->generation, 'succeeded'));

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generations.data.0.duplicateDeliveries', 0));
    }

    public function test_two_different_events_with_the_same_result_charge_once(): void
    {
        // E.g. a dashboard resend gets a new event id.
        $this->postWebhook($this->webhookEvent($this->generation, 'succeeded'))->assertOk();
        $this->postWebhook($this->webhookEvent($this->generation, 'succeeded'))->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame(1, $this->entries($this->generation, LedgerType::Charge));
        $this->assertSame(2, WebhookEvent::query()->count());
    }

    public function test_a_failure_while_applying_records_nothing_so_the_retry_counts(): void
    {
        $eventId = 'evt_retried_by_pixelforge';

        // Applying throws (a succeeded object without usage can't be billed).
        $this->postWebhook($this->webhookEvent($this->generation, 'succeeded', ['usage' => null], $eventId))
            ->assertStatus(500);

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame(GenerationStatus::Pending, $this->fresh()->status);

        // Pixelforge's retry of the same event is processed, not discarded.
        $this->postWebhook($this->webhookEvent($this->generation, 'succeeded', eventId: $eventId))
            ->assertOk()
            ->assertJson(['status' => 'applied']);

        $this->assertSame(GenerationStatus::Succeeded, $this->fresh()->status);
        $this->assertSame(1, $this->entries($this->generation, LedgerType::Charge));
    }

    public function test_processing_after_succeeded_changes_nothing(): void
    {
        $this->postWebhook($this->webhookEvent($this->generation, 'succeeded'))->assertOk();
        $this->postWebhook($this->webhookEvent($this->generation, 'processing'))->assertOk();

        $this->assertSame(GenerationStatus::Succeeded, $this->fresh()->status);
    }

    public function test_webhook_before_the_submit_response_is_matched_by_local_id(): void
    {
        // The row has no pixelforge_id yet: the POST hasn't returned.
        $this->assertNull($this->generation->pixelforge_id);

        $this->postWebhook($this->webhookEvent($this->generation, 'succeeded'))->assertJson(['status' => 'applied']);

        // Then the submit job runs: the row is no longer pending, so it
        // sends nothing and changes nothing.
        Http::fake(['*/v1/generations' => Http::response($this->pixelforgeObject($this->generation), 202)]);
        $job = (new SubmitGeneration($this->generation->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $this->assertSame(GenerationStatus::Succeeded, $this->fresh()->status);
        $this->assertSame(1, $this->entries($this->generation, LedgerType::Charge));
    }

    public function test_generation_failed_is_free_and_not_retried(): void
    {
        Queue::fake();

        $this->postWebhook($this->webhookEvent($this->generation, 'failed'))->assertOk();

        $this->assertSame(GenerationStatus::Failed, $this->fresh()->status);
        $this->assertSame(0, $this->fresh()->charge_nanos);
        $this->assertSame(self::START, $this->balance());
        $this->assertNull($this->retryOf($this->generation));
        Queue::assertNothingPushed();
    }

    public function test_internal_error_is_retried_once_as_a_new_linked_row(): void
    {
        Queue::fake();

        $this->postWebhook($this->webhookEvent($this->generation, 'failed', [
            'error' => ['code' => 'internal_error', 'message' => 'Something went wrong on our side.'],
        ]))->assertOk();

        $this->assertSame(GenerationStatus::Failed, $this->fresh()->status);
        $this->assertSame(0, $this->fresh()->charge_nanos);
        $this->assertSame(1, $this->entries($this->generation, LedgerType::Release));

        $retry = $this->retryOf($this->generation);
        $this->assertNotNull($retry);
        $this->assertSame(GenerationStatus::Pending, $retry->status);
        $this->assertSame($this->generation->prompt, $retry->prompt);
        $this->assertSame($this->generation->size, $retry->size);
        $this->assertSame($this->generation->quality, $retry->quality);
        $this->assertSame(self::MAX_PRICE, $retry->max_price_nanos);
        $this->assertSame(1, $this->entries($retry, LedgerType::Hold));
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $retry->id);

        // The old hold went back and the new one was taken.
        $this->assertSame(self::START - self::MAX_PRICE, $this->balance());
    }

    public function test_a_retry_that_hits_internal_error_is_not_retried_again(): void
    {
        Queue::fake();
        $this->postWebhook($this->webhookEvent($this->generation, 'failed', ['error' => ['code' => 'internal_error', 'message' => 'x']]));
        $retry = $this->retryOf($this->generation) ?? $this->fail('No retry was created.');

        $this->postWebhook($this->webhookEvent($retry, 'failed', ['error' => ['code' => 'internal_error', 'message' => 'x']]))->assertOk();

        $this->assertSame(GenerationStatus::Failed, $this->fresh($retry)->status);
        $this->assertNull($this->retryOf($retry));
        $this->assertSame(2, Generation::query()->count());
        $this->assertSame(self::START, $this->balance());
    }

    public function test_a_late_duplicate_failure_for_the_original_touches_neither_row(): void
    {
        Queue::fake();
        $failed = $this->webhookEvent($this->generation, 'failed', ['error' => ['code' => 'internal_error', 'message' => 'x']]);
        $this->postWebhook($failed);
        $retry = $this->retryOf($this->generation) ?? $this->fail('No retry was created.');
        $retryBefore = $this->fresh($retry)->toArray();

        // Same event again, and a new event id for the same failure.
        $this->postWebhook($failed)->assertJson(['status' => 'duplicate']);
        $this->postWebhook($this->webhookEvent($this->generation, 'failed', ['error' => ['code' => 'internal_error', 'message' => 'x']]))->assertOk();

        $this->assertSame(2, Generation::query()->count());
        $this->assertEquals($retryBefore, $this->fresh($retry)->toArray());
        $this->assertSame(1, $this->entries($this->generation, LedgerType::Release));
        $this->assertSame(self::START - self::MAX_PRICE, $this->balance());
    }

    public function test_no_automatic_retry_when_the_balance_cant_cover_it(): void
    {
        Queue::fake();
        // Spend everything but the held amount, and one nanodollar more:
        // once the failure returns the hold, the user is one short of the
        // retry's maximum.
        $ledger = app(LedgerService::class);
        $elsewhere = Generation::factory()->for($this->user)->status(GenerationStatus::Succeeded)->create();
        $ledger->charge($elsewhere, $ledger->available($this->user) + 1);

        $this->postWebhook($this->webhookEvent($this->generation, 'failed', ['error' => ['code' => 'internal_error', 'message' => 'x']]))
            ->assertOk();

        $this->assertSame(GenerationStatus::Failed, $this->fresh()->status);
        $this->assertNull($this->retryOf($this->generation));
        $this->assertSame(self::MAX_PRICE - 1, $ledger->available($this->user));
        Queue::assertNothingPushed();
    }

    public function test_no_automatic_retry_for_a_deleted_account(): void
    {
        Queue::fake();
        $this->user->delete();

        $this->postWebhook($this->webhookEvent($this->generation, 'failed', ['error' => ['code' => 'internal_error', 'message' => 'x']]))
            ->assertOk();

        $this->assertSame(GenerationStatus::Failed, $this->fresh()->status);
        $this->assertNull($this->retryOf($this->generation));
    }

    public function test_ping_is_answered_and_recorded(): void
    {
        $this->postWebhook(['id' => 'evt_ping_1', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-22T14:00:00Z', 'data' => []])
            ->assertOk()
            ->assertJson(['status' => 'recorded']);

        $this->assertNotNull(WebhookEvent::query()->sole()->processed_at);
    }

    public function test_an_event_for_an_unknown_generation_waits_for_reconcile(): void
    {
        $event = [
            'id' => 'evt_unknown',
            'object' => 'event',
            'type' => 'generation.succeeded',
            'created_at' => '2026-11-22T14:00:00Z',
            'data' => ['object' => ['id' => 'gen_not_ours', 'object' => 'generation', 'status' => 'succeeded', 'metadata' => []]],
        ];

        $this->postWebhook($event)->assertOk()->assertJson(['status' => 'unmatched']);

        $stored = WebhookEvent::query()->sole();
        $this->assertNull($stored->processed_at);
        $this->assertSame('gen_not_ours', $stored->pixelforge_generation_id);
    }

    public function test_malformed_json_is_refused(): void
    {
        $this->postWebhook([], body: 'not json')->assertStatus(400);

        $this->assertDatabaseCount('webhook_events', 0);
    }
}
