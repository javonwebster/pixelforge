<?php

namespace Tests\Feature;

use App\Enums\LedgerType;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\GenerationUpdater;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class CreditsTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePixelforge();
        $this->user = $this->userWithCredit(5_000_000_000);
    }

    private function available(): int
    {
        return app(LedgerService::class)->available($this->user);
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function acceptedAmounts(): array
    {
        return [
            'preset as text' => ['10', 10_000_000_000],
            'preset as a number' => [20, 20_000_000_000],
            'dollars and cents' => ['12.34', 12_340_000_000],
            'one decimal' => ['7.5', 7_500_000_000],
            'the minimum' => ['1', 1_000_000_000],
            'the maximum' => ['500.00', 500_000_000_000],
        ];
    }

    #[DataProvider('acceptedAmounts')]
    public function test_buys_any_amount_in_range_exactly(mixed $amount, int $nanos): void
    {
        $this->actingAs($this->user)
            ->post(route('credits.store'), ['amount' => $amount])
            ->assertSessionHasNoErrors();

        $this->assertSame(5_000_000_000 + $nanos, $this->available());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function refusedAmounts(): array
    {
        return [
            'below $1' => ['0.99'],
            'above $500' => ['500.01'],
            'zero' => ['0'],
            'negative' => ['-5'],
            'fractions of a cent' => ['12.345'],
            'exponent' => ['1e2'],
            'not a number' => ['ten'],
            'empty' => [''],
            'a list' => [['10']],
        ];
    }

    #[DataProvider('refusedAmounts')]
    public function test_refuses_amounts_outside_the_rules(mixed $amount): void
    {
        $this->actingAs($this->user)
            ->post(route('credits.store'), ['amount' => $amount])
            ->assertSessionHasErrors('amount');

        $this->assertSame(5_000_000_000, $this->available());
    }

    public function test_history_lists_the_ledger_newest_first_with_a_running_balance(): void
    {
        // Purchase $5 (setUp), submit (hold), succeed (release + charge).
        $generation = $this->submitGeneration($this->user, 'A lighthouse on a cliff at dusk');
        app(GenerationUpdater::class)->apply($this->pixelforgeObject($generation, 'succeeded'));

        $this->actingAs($this->user)
            ->get(route('credits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('credits/Index')
                ->has('entries.data', 4)
                ->where('entries.data.0.type', 'charge')
                ->where('entries.data.0.label', 'Charged for a generation')
                ->where('entries.data.0.prompt', 'A lighthouse on a cliff at dusk')
                ->where('entries.data.0.generationId', $generation->id)
                ->where('entries.data.0.amount.display', '-$0.07')
                ->where('entries.data.0.amount.exact', '-$0.073825000')
                ->where('entries.data.0.balanceAfter.exact', '$4.926175000')
                ->where('entries.data.1.type', 'release')
                ->where('entries.data.1.amount.display', '+$0.09')
                ->where('entries.data.1.balanceAfter.exact', '$5.000000000')
                ->where('entries.data.2.type', 'hold')
                // $5 minus the 31-character prompt's maximum of $0.0863125.
                ->where('entries.data.2.balanceAfter.exact', '$4.913687500')
                ->where('entries.data.3.type', 'purchase')
                ->where('entries.data.3.label', 'Credits purchased')
                ->where('entries.data.3.amount.display', '+$5.00')
                ->where('entries.data.3.prompt', null)
                ->where('balance.available.exact', '$4.926175000'));
    }

    public function test_history_shows_only_the_users_own_entries(): void
    {
        $this->userWithCredit(9_000_000_000);   // someone else

        $this->actingAs($this->user)
            ->get(route('credits.index'))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    }

    public function test_filters_by_date_keep_the_true_running_balance(): void
    {
        $user = $this->userWithCredit(0);
        $this->travelTo('2026-11-19 12:00:00');
        app(LedgerService::class)->purchase($user, 5_000_000_000);
        $this->travelTo('2026-11-20 12:00:00');
        app(LedgerService::class)->purchase($user, 10_000_000_000);
        $this->travelTo('2026-11-22 12:00:00');
        app(LedgerService::class)->purchase($user, 20_000_000_000);

        $this->actingAs($user)
            ->get(route('credits.index', ['from' => '2026-11-22', 'to' => '2026-11-22']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.amount.exact', '$20.000000000')
                // $5 + $10 + $20, though only the last row is shown.
                ->where('entries.data.0.balanceAfter.exact', '$35.000000000')
                ->where('filters.from', '2026-11-22'));
    }

    public function test_filters_by_absolute_amount(): void
    {
        $generation = $this->submitGeneration($this->user);   // hold of -$0.0866125

        $this->actingAs($this->user)
            ->get(route('credits.index', ['min' => '0.05', 'max' => '1']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.type', 'hold')
                ->where('entries.data.0.generationId', $generation->id));
    }

    public function test_bad_filters_are_refused(): void
    {
        $this->actingAs($this->user)->get(route('credits.index', ['from' => 'yesterday']))->assertSessionHasErrors('from');
        $this->actingAs($this->user)->get(route('credits.index', ['from' => '2026-11-22', 'to' => '2026-11-21']))->assertSessionHasErrors('to');
        $this->actingAs($this->user)->get(route('credits.index', ['min' => '1e3']))->assertSessionHasErrors('min');
    }

    public function test_history_is_paginated(): void
    {
        for ($i = 0; $i < 30; $i++) {
            LedgerEntry::create(['user_id' => $this->user->id, 'type' => LedgerType::Purchase, 'amount_nanos' => 1_000_000_000]);
        }

        $this->actingAs($this->user)
            ->get(route('credits.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 25)
                ->where('entries.last_page', 2)
                ->where('entries.data.0.balanceAfter.exact', '$35.000000000'));
    }

    public function test_guests_are_sent_to_login(): void
    {
        auth()->logout();

        $this->get(route('credits.index'))->assertRedirect(route('login'));
    }
}
