<?php

namespace Tests\Feature\Generations;

use App\Actions\CreateGeneration;
use App\Enums\GenerationStatus;
use App\Enums\LedgerType;
use App\Enums\Quality;
use App\Enums\Size;
use App\Exceptions\InsufficientBalanceException;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use App\Services\Pricing\PricingCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class CreateGenerationTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePixelforge();
    }

    public function test_creates_a_pending_generation_with_a_hold_and_queues_the_submit(): void
    {
        $user = $this->userWithCredit(5_000_000_000);

        $generation = $this->submitGeneration($user);

        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(72_500_000, $generation->estimated_price_nanos);
        $this->assertSame(self::MAX_PRICE, $generation->max_price_nanos);
        $this->assertDatabaseHas('ledger_entries', [
            'generation_id' => $generation->id,
            'type' => LedgerType::Hold->value,
            'amount_nanos' => -self::MAX_PRICE,
        ]);
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $generation->id);

        // Nothing is sent to Pixelforge on the request itself.
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v1/generations'));
    }

    public function test_refuses_when_the_balance_cant_cover_the_maximum(): void
    {
        $user = $this->userWithCredit(50_000_000);   // $0.05

        try {
            $this->submitGeneration($user);
            $this->fail('Expected InsufficientBalanceException.');
        } catch (InsufficientBalanceException $e) {
            $this->assertSame('This needs up to $0.0866. Your balance is $0.0500. Buy credits to continue.', $e->getMessage());
            $this->assertSame(self::MAX_PRICE, $e->requiredNanos);
            $this->assertSame(50_000_000, $e->availableNanos);
        }

        $this->assertDatabaseCount('generations', 0);
        $this->assertSame(0, LedgerEntry::query()->where('type', LedgerType::Hold)->count());
        Queue::assertNothingPushed();
    }

    public function test_accepts_when_the_balance_covers_the_maximum_exactly(): void
    {
        $user = $this->userWithCredit(self::MAX_PRICE);

        $this->submitGeneration($user);

        $this->assertSame(0, app(LedgerService::class)->available($user));
    }

    public function test_held_credit_counts_against_the_next_submit(): void
    {
        // Enough for one maximum, not two.
        $user = $this->userWithCredit(150_000_000);

        $this->submitGeneration($user);

        $this->expectException(InsufficientBalanceException::class);

        $this->submitGeneration($user);
    }

    public function test_prices_with_current_pricing_rates(): void
    {
        // As if /v1/pricing had just returned a doubled output rate.
        Cache::put(PricingCatalog::CACHE_KEY, ['output_tokens' => '80.00'] + $this->documentedRates(), 3600);
        $user = $this->userWithCredit(5_000_000_000);

        $generation = $this->submitGeneration($user);

        // Output doubles from 44,000,000 to 88,000,000 n of the 58,000,000 n
        // estimate cost: 102,000,000 n cost, 127,500,000 n price.
        $this->assertSame(127_500_000, $generation->estimated_price_nanos);
    }

    public function test_stores_reference_images_and_counts_them(): void
    {
        $user = $this->userWithCredit(5_000_000_000);
        Queue::fake();

        $generation = app(CreateGeneration::class)->handle($user, 'a cat', Size::Square, Quality::Low, [
            'https://example.com/a.png',
            'https://example.com/b.png',
        ]);

        $this->assertSame(['https://example.com/a.png', 'https://example.com/b.png'], $generation->fresh()?->reference_images);
        // 2 × 765 image tokens × $10/1M = 15,300,000 n on top of the rest.
        $withoutImages = app(CreateGeneration::class)->handle($user, 'a cat', Size::Square, Quality::Low);
        $this->assertSame(
            $withoutImages->max_price_nanos + 19_125_000,   // 15,300,000 × 1.25
            $generation->max_price_nanos,
        );
    }

    public function test_links_an_automatic_retry_to_its_original(): void
    {
        $user = $this->userWithCredit(5_000_000_000);
        $original = $this->submitGeneration($user);

        $retry = app(CreateGeneration::class)->handle($user, $original->prompt, $original->size, $original->quality, retryOf: $original);

        $this->assertSame($original->id, $retry->retry_of_id);
        $this->assertSame(2, Generation::query()->count());
    }
}
