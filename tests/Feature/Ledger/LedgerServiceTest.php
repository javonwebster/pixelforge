<?php

namespace Tests\Feature\Ledger;

use App\Enums\LedgerType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(LedgerService::class);
        $this->fakePixelforge();
    }

    public function test_balance_is_the_sum_of_entries(): void
    {
        $user = $this->userWithCredit(5_000_000_000);
        $this->ledger->purchase($user, 10_000_000_000);

        $this->assertSame(15_000_000_000, $this->ledger->available($user));
        $this->assertSame(0, $this->ledger->held($user));
    }

    public function test_hold_moves_credit_from_available_to_held(): void
    {
        $user = $this->userWithCredit(5_000_000_000);

        $this->submitGeneration($user);

        $this->assertSame(5_000_000_000 - self::MAX_PRICE, $this->ledger->available($user));
        $this->assertSame(self::MAX_PRICE, $this->ledger->held($user));
    }

    public function test_release_returns_the_hold_once(): void
    {
        $user = $this->userWithCredit(5_000_000_000);
        $generation = $this->submitGeneration($user);

        $this->ledger->release($generation);
        $this->ledger->release($generation);

        $this->assertSame(5_000_000_000, $this->ledger->available($user));
        $this->assertSame(0, $this->ledger->held($user));
        $this->assertSame(1, LedgerEntry::query()->where('type', LedgerType::Release)->count());
    }

    public function test_balances_are_per_user(): void
    {
        $alice = $this->userWithCredit(5_000_000_000);
        $bob = $this->userWithCredit(1_000_000_000);
        $this->submitGeneration($alice);

        $this->assertSame(1_000_000_000, $this->ledger->available($bob));
        $this->assertSame(0, $this->ledger->held($bob));
    }

    public function test_database_refuses_a_second_charge_for_one_generation(): void
    {
        $user = $this->userWithCredit(5_000_000_000);
        $generation = $this->submitGeneration($user);
        $this->ledger->charge($generation, self::SUCCEEDED_PRICE);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->ledger->charge($generation, self::SUCCEEDED_PRICE);
    }

    public function test_database_refuses_a_second_hold_for_one_generation(): void
    {
        $user = $this->userWithCredit(5_000_000_000);
        $generation = $this->submitGeneration($user);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->ledger->hold($generation);
    }

    public function test_database_enforces_the_sign_of_each_entry_type(): void
    {
        $user = $this->userWithCredit(5_000_000_000);
        $generation = Generation::factory()->for($user)->create();

        $this->expectException(QueryException::class);

        // A positive "charge" would add money.
        LedgerEntry::create([
            'user_id' => $user->id,
            'generation_id' => $generation->id,
            'type' => LedgerType::Charge,
            'amount_nanos' => 1,
        ]);
    }

    public function test_purchase_must_be_positive(): void
    {
        $user = $this->userWithCredit(0);

        $this->expectException(\InvalidArgumentException::class);

        $this->ledger->purchase($user, 0);
    }
}
