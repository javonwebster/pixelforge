<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Enums\LedgerType;
use App\Enums\PixelforgeErrorCode;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\GenerationUpdater;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Money and ordering rules for every status change after creation.
 * Webhook-specific cases (signatures, dedupe) come with the webhook
 * endpoint.
 */
class GenerationUpdaterTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private const int START = 5_000_000_000;

    private GenerationUpdater $updater;

    private User $user;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePixelforge();
        $this->updater = app(GenerationUpdater::class);
        $this->user = $this->userWithCredit(self::START);
        $this->generation = $this->submitGeneration($this->user);
    }

    private function fresh(): Generation
    {
        return $this->generation->fresh() ?? $this->fail('Generation disappeared.');
    }

    private function balance(): int
    {
        return app(LedgerService::class)->available($this->user);
    }

    private function entries(LedgerType $type): int
    {
        return LedgerEntry::query()->where('generation_id', $this->generation->id)->where('type', $type)->count();
    }

    public function test_success_charges_exactly_the_actual_price(): void
    {
        $this->updater->apply($this->pixelforgeObject($this->generation, 'succeeded'));

        $fresh = $this->fresh();
        $this->assertSame(GenerationStatus::Succeeded, $fresh->status);
        $this->assertSame(self::SUCCEEDED_COST, $fresh->provider_cost_nanos);
        $this->assertSame(self::SUCCEEDED_PRICE, $fresh->charge_nanos);
        $this->assertSame([52, 0, 1_100, 1_184], [$fresh->input_text_tokens, $fresh->input_image_tokens, $fresh->output_tokens, $fresh->reasoning_tokens]);
        $this->assertSame('https://files.pixelforge.example/img.png', $fresh->image_url);

        // The balance falls by the actual charge, not the hold.
        $this->assertSame(self::START - self::SUCCEEDED_PRICE, $this->balance());
        $this->assertSame(0, app(LedgerService::class)->held($this->user));
    }

    public function test_the_same_success_twice_charges_once(): void
    {
        $object = $this->pixelforgeObject($this->generation, 'succeeded');

        $this->updater->apply($object);
        $this->updater->apply($object);

        $this->assertSame(1, $this->entries(LedgerType::Charge));
        $this->assertSame(1, $this->entries(LedgerType::Release));
        $this->assertSame(self::START - self::SUCCEEDED_PRICE, $this->balance());
    }

    public function test_failure_is_free_and_returns_the_balance(): void
    {
        $this->updater->apply($this->pixelforgeObject($this->generation, 'failed'));

        $fresh = $this->fresh();
        $this->assertSame(GenerationStatus::Failed, $fresh->status);
        $this->assertSame('generation_failed', $fresh->error_code);
        $this->assertSame(0, $fresh->charge_nanos);
        $this->assertSame(0, $fresh->provider_cost_nanos);
        $this->assertSame(0, $this->entries(LedgerType::Charge));
        $this->assertSame(self::START, $this->balance());
    }

    public function test_status_only_moves_forward(): void
    {
        $this->updater->apply($this->pixelforgeObject($this->generation, 'processing'));
        $this->updater->apply($this->pixelforgeObject($this->generation, 'queued'));

        $this->assertSame(GenerationStatus::Processing, $this->fresh()->status);
    }

    public function test_nothing_changes_a_finished_generation(): void
    {
        $this->updater->apply($this->pixelforgeObject($this->generation, 'succeeded'));
        $this->updater->apply($this->pixelforgeObject($this->generation, 'processing'));
        $this->updater->apply($this->pixelforgeObject($this->generation, 'failed'));

        $this->assertSame(GenerationStatus::Succeeded, $this->fresh()->status);
        $this->assertSame(1, $this->entries(LedgerType::Release));
        $this->assertSame(1, $this->entries(LedgerType::Charge));
        $this->assertSame(self::START - self::SUCCEEDED_PRICE, $this->balance());
    }

    public function test_matches_by_local_id_before_the_pixelforge_id_is_known(): void
    {
        // A webhook can arrive before the POST response: nothing stored yet
        // links the row to Pixelforge except metadata.local_id.
        $this->assertNull($this->generation->pixelforge_id);

        $this->updater->apply($this->pixelforgeObject($this->generation, 'succeeded'));

        // The POST's own 202 lands afterwards and doesn't overwrite it.
        $this->updater->apply($this->pixelforgeObject($this->generation, 'queued'));

        $this->assertSame(GenerationStatus::Succeeded, $this->fresh()->status);
        $this->assertNotNull($this->fresh()->pixelforge_id);
    }

    public function test_matches_by_pixelforge_id_without_metadata(): void
    {
        $object = $this->pixelforgeObject($this->generation);
        $this->updater->apply($object);

        $this->updater->apply(['metadata' => []] + $this->pixelforgeObject($this->generation, 'processing'));

        $this->assertSame(GenerationStatus::Processing, $this->fresh()->status);
    }

    public function test_an_event_for_another_pixelforge_generation_is_ignored(): void
    {
        $this->updater->apply($this->pixelforgeObject($this->generation));

        $result = $this->updater->apply($this->pixelforgeObject($this->generation, 'failed', ['id' => 'gen_someotherone']));

        $this->assertNull($result);
        $this->assertSame(GenerationStatus::Queued, $this->fresh()->status);
    }

    public function test_unknown_generation_returns_null(): void
    {
        $this->assertNull($this->updater->apply(['id' => 'gen_unknown', 'status' => 'succeeded', 'metadata' => []]));
        $this->assertNull($this->updater->apply(['id' => 'gen_unknown', 'status' => 'queued', 'metadata' => ['local_id' => 'not-a-uuid']]));
    }

    public function test_submission_failed_that_later_succeeds_is_charged_once(): void
    {
        $this->updater->markSubmissionFailed($this->generation, PixelforgeErrorCode::ConnectionError, 'Gave up.');
        $this->assertSame(self::START, $this->balance());   // hold returned

        $this->updater->apply($this->pixelforgeObject($this->generation, 'succeeded'));

        $this->assertSame(GenerationStatus::Succeeded, $this->fresh()->status);
        $this->assertSame(1, $this->entries(LedgerType::Release));
        $this->assertSame(1, $this->entries(LedgerType::Charge));
        $this->assertSame(self::START - self::SUCCEEDED_PRICE, $this->balance());
    }

    public function test_rejected_never_moves(): void
    {
        $this->updater->reject($this->generation, PixelforgeErrorCode::ContentPolicyViolation, 'Blocked.');

        $this->updater->apply($this->pixelforgeObject($this->generation, 'succeeded'));

        $this->assertSame(GenerationStatus::Rejected, $this->fresh()->status);
        $this->assertSame(self::START, $this->balance());
    }

    public function test_rejection_only_applies_to_a_pending_generation(): void
    {
        $this->updater->apply($this->pixelforgeObject($this->generation, 'processing'));

        $this->updater->reject($this->generation, PixelforgeErrorCode::DailyLimitExceeded, 'Too late.');

        $this->assertSame(GenerationStatus::Processing, $this->fresh()->status);
        $this->assertSame(self::START - self::MAX_PRICE, $this->balance());
    }

    public function test_actual_above_the_hold_is_still_charged(): void
    {
        Log::spy();
        $object = $this->pixelforgeObject($this->generation, 'succeeded');
        $object['usage']['reasoning_tokens'] = 9_000;   // impossible for medium, but billed

        $this->updater->apply($object);

        // 52×5,000 + 1,100×40,000 + 9,000×12,500 = 156,760,000 n cost.
        $this->assertSame(195_950_000, $this->fresh()->charge_nanos);
        $this->assertSame(self::START - 195_950_000, $this->balance());
        Log::shouldHaveReceived('warning')->with('Actual price above the hold.', \Mockery::any())->once();
    }

    public function test_actual_cost_uses_the_rates_on_the_generation(): void
    {
        $this->updater->apply($this->pixelforgeObject($this->generation, 'queued', [
            'rates' => ['output_tokens' => '20.00'] + $this->documentedRates(),
        ]));

        // A later object with different rates doesn't replace them.
        $this->updater->apply($this->pixelforgeObject($this->generation, 'succeeded'));

        // 52×5,000 + 1,100×20,000 + 1,184×12,500 = 37,060,000 n.
        $this->assertSame(37_060_000, $this->fresh()->provider_cost_nanos);
    }

    public function test_completed_at_comes_from_pixelforge_not_our_clock(): void
    {
        $this->travelTo('2026-11-23 00:00:07');

        $this->updater->apply($this->pixelforgeObject($this->generation, 'succeeded', ['completed_at' => '2026-11-22T23:59:58Z']));

        $this->assertSame('2026-11-22 23:59:58', $this->fresh()->completed_at?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_success_without_usage_changes_nothing(): void
    {
        try {
            $this->updater->apply($this->pixelforgeObject($this->generation, 'succeeded', ['usage' => null]));
            $this->fail('Expected UnexpectedValueException.');
        } catch (UnexpectedValueException) {
        }

        $this->assertSame(GenerationStatus::Pending, $this->fresh()->status);
        $this->assertNull($this->fresh()->pixelforge_id);   // rolled back with it
        $this->assertSame(0, $this->entries(LedgerType::Charge));
    }
}
