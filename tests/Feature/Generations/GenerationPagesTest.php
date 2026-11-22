<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Enums\LedgerType;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class GenerationPagesTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private const int START = 5_000_000_000;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePixelforge();
        $this->user = $this->userWithCredit(self::START);
    }

    /** @return array<string, mixed> */
    private function validInput(array $overrides = []): array
    {
        return $overrides + [
            'prompt' => str_repeat('a', 200),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ];
    }

    public function test_generate_page_shows_the_form_and_balance(): void
    {
        $this->actingAs($this->user)
            ->get(route('generations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Create')
                ->where('balance.available.display', '$5.00')
                ->where('balance.held.display', '$0.00')
                ->where('sizes', ['1024x1024', '1536x1024', '1024x1536'])
                ->where('qualities', ['low', 'medium', 'high'])
                ->where('prefill.size', '1024x1024')
                ->where('prefill.quality', 'medium')
                ->where('maxPromptCharacters', 4_000));
    }

    public function test_try_again_prefills_from_the_users_own_generation(): void
    {
        $generation = Generation::factory()->for($this->user)->status(GenerationStatus::Failed)->create([
            'prompt' => 'A red fox in snow',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/fox.jpg'],
        ]);

        $this->actingAs($this->user)
            ->get(route('generations.create', ['from' => $generation->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('prefill.prompt', 'A red fox in snow')
                ->where('prefill.size', '1536x1024')
                ->where('prefill.quality', 'high')
                ->where('prefill.reference_images', ['https://example.com/fox.jpg']));
    }

    public function test_another_users_generation_is_not_found(): void
    {
        $theirs = Generation::factory()->create();

        $this->actingAs($this->user)
            ->get(route('generations.create', ['from' => $theirs->id]))
            ->assertNotFound();
    }

    public function test_a_malformed_generation_id_is_not_found(): void
    {
        $this->actingAs($this->user)
            ->get(route('generations.create', ['from' => 'not-a-uuid']))
            ->assertNotFound();
    }

    public function test_submitting_holds_the_maximum_and_goes_to_history(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->post(route('generations.store'), $this->validInput())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('generations.index'));

        $generation = Generation::query()->sole();
        $this->assertSame($this->user->id, $generation->user_id);
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(self::START - self::MAX_PRICE, app(LedgerService::class)->available($this->user));
        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_submitting_with_too_little_balance_shows_why_and_creates_nothing(): void
    {
        Queue::fake();
        $poor = $this->userWithCredit(50_000_000);

        $this->actingAs($poor)
            ->post(route('generations.store'), $this->validInput())
            ->assertSessionHasErrors(['balance' => 'This needs up to $0.09. Your balance is $0.05. Buy credits to continue.']);

        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'no prompt' => [['prompt' => ''], 'prompt'],
            'prompt over 4,000 characters' => [['prompt' => str_repeat('a', 4_001)], 'prompt'],
            'unknown size' => [['size' => '512x512'], 'size'],
            'unknown quality' => [['quality' => 'ultra'], 'quality'],
            'five reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
            'non-http reference image' => [['reference_images' => ['javascript:alert(1)']], 'reference_images.0'],
            'ftp reference image' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidInputs')]
    public function test_validation_mirrors_pixelforges_rules(array $overrides, string $field): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->post(route('generations.store'), $this->validInput($overrides))
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('generations', 0);
    }

    public function test_a_4000_character_unicode_prompt_is_allowed(): void
    {
        Queue::fake();

        // 4,000 characters but 16,000 bytes: the limit is in characters.
        $this->actingAs($this->user)
            ->post(route('generations.store'), $this->validInput(['prompt' => str_repeat('🎨', 4_000)]))
            ->assertSessionHasNoErrors();
    }

    public function test_blank_reference_image_fields_are_ignored(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->post(route('generations.store'), $this->validInput(['reference_images' => ['', 'https://example.com/a.png', '   ']]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['https://example.com/a.png'], Generation::query()->sole()->reference_images);
    }

    public function test_quote_matches_the_worked_example(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('generations.quote'), $this->validInput())
            ->assertOk()
            ->assertExactJson([
                'estimate' => ['nanos' => 72_500_000, 'display' => '$0.07', 'exact' => '$0.072500000'],
                'maximum' => ['nanos' => 86_612_500, 'display' => '$0.09', 'exact' => '$0.086612500'],
                'available' => ['nanos' => self::START, 'display' => '$5.00', 'exact' => '$5.000000000'],
                'enough' => true,
                'shortfallMessage' => null,
            ]);
    }

    public function test_quote_allows_an_empty_prompt_while_typing(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('generations.quote'), $this->validInput(['prompt' => '']))
            ->assertOk()
            ->assertJsonPath('enough', true);
    }

    public function test_quote_says_when_the_balance_is_not_enough(): void
    {
        $this->actingAs($this->userWithCredit(50_000_000))
            ->postJson(route('generations.quote'), $this->validInput())
            ->assertJsonPath('enough', false)
            // Word for word what the submit refuses with.
            ->assertJsonPath('shortfallMessage', 'This needs up to $0.09. Your balance is $0.05. Buy credits to continue.');
    }

    public function test_shortfall_message_never_shows_the_same_amount_twice(): void
    {
        // $0.086 available, $0.0866125 needed: both round to $0.09, so the
        // message rounds what's needed up and what's available down.
        $this->actingAs($this->userWithCredit(86_000_000))
            ->postJson(route('generations.quote'), $this->validInput())
            ->assertJsonPath('shortfallMessage', 'This needs up to $0.09. Your balance is $0.08. Buy credits to continue.');
    }

    public function test_quote_counts_reference_images(): void
    {
        $withImages = $this->actingAs($this->user)
            ->postJson(route('generations.quote'), $this->validInput(['reference_images' => ['https://example.com/a.png']]))
            ->json('maximum.nanos');

        // 765 tokens × $10 per 1M × 1.25 = 9,562,500 n more.
        $this->assertSame(86_612_500 + 9_562_500, $withImages);
    }

    public function test_history_lists_only_the_users_generations_newest_first(): void
    {
        $older = Generation::factory()->for($this->user)->create(['created_at' => now()->subMinute()]);
        $newer = Generation::factory()->for($this->user)->create();
        Generation::factory()->create();   // someone else's

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Index')
                ->has('generations.data', 2)
                ->where('generations.data.0.id', $newer->id)
                ->where('generations.data.1.id', $older->id)
                ->where('generations.data.0.label', 'Waiting for a slot')
                ->where('generations.data.0.finished', false)
                ->where('generations.data.0.estimatedPrice.display', '$0.07')
                ->where('generations.data.0.actualPrice', null)
                ->has('balance.available'));
    }

    public function test_history_is_paginated(): void
    {
        Generation::factory()->count(15)->for($this->user)->create();

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('generations.data', 12)
                ->where('generations.meta.last_page', 2));
    }

    public function test_history_poll_reloads_only_the_list_and_balance(): void
    {
        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly(['generations', 'balance'], fn (Assert $reload) => $reload
                    ->has('generations')
                    ->has('balance')));
    }

    public function test_an_expired_image_shows_a_placeholder(): void
    {
        Generation::factory()->for($this->user)->status(GenerationStatus::Succeeded)->create([
            'image_url' => 'https://files.pixelforge.example/old.png',
            'image_url_expires_at' => now()->subMinute(),
            'charge_nanos' => self::SUCCEEDED_PRICE,
        ]);

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.image.url', null)
                ->where('generations.data.0.image.expired', true)
                ->where('generations.data.0.actualPrice.display', '$0.07')
                ->where('generations.data.0.actualPrice.exact', '$0.073825000'));
    }

    public function test_a_live_image_is_shown(): void
    {
        Generation::factory()->for($this->user)->status(GenerationStatus::Succeeded)->create([
            'image_url' => 'https://files.pixelforge.example/new.png',
            'image_url_expires_at' => now()->addHours(23),
        ]);

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.image.url', 'https://files.pixelforge.example/new.png')
                ->where('generations.data.0.image.expired', false));
    }

    public function test_an_automatic_retry_links_to_its_original(): void
    {
        $original = Generation::factory()->for($this->user)->status(GenerationStatus::Failed)->create([
            'error_code' => 'internal_error',
            'created_at' => now()->subMinute(),
        ]);
        $retry = Generation::factory()->for($this->user)->create(['retry_of_id' => $original->id]);

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.id', $retry->id)
                ->where('generations.data.0.retryOf.id', $original->id)
                ->where('generations.data.1.retriedAs', $retry->id)
                ->where('generations.data.1.detail', 'Pixelforge had an error, retried automatically'));
    }

    public function test_buying_credits_adds_a_purchase(): void
    {
        $this->actingAs($this->user)
            ->from(route('generations.create'))
            ->post(route('credits.store'), ['amount' => 10])
            ->assertRedirect(route('generations.create'));

        $this->assertSame(self::START + 10_000_000_000, app(LedgerService::class)->available($this->user));
        $this->assertSame(2, $this->user->ledgerEntries()->where('type', LedgerType::Purchase)->count());
    }

    public function test_only_the_offered_credit_amounts_can_be_bought(): void
    {
        $this->actingAs($this->user)
            ->post(route('credits.store'), ['amount' => 1_000_000])
            ->assertSessionHasErrors('amount');

        $this->assertSame(self::START, app(LedgerService::class)->available($this->user));
    }

    public function test_guests_cannot_buy_credits_or_quote(): void
    {
        $this->post(route('credits.store'), ['amount' => 5])->assertRedirect(route('login'));
        $this->postJson(route('generations.quote'), $this->validInput())->assertUnauthorized();
    }
}
