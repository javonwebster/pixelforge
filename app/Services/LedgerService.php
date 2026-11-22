<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only code that writes `ledger_entries`. A user's balance is the sum
 * of their entries; nothing else stores a balance that could drift.
 *
 * - Submit:  hold −maximum
 * - Success: release +maximum, charge −actual
 * - Failure, rejection, giving up: release +maximum
 *
 * The unique (generation_id, type) index means a generation can be held,
 * released and charged at most once each, whatever retries or duplicate
 * webhooks do. Callers hold a row lock (the user's on submit, the
 * generation's in the updater), so the "already released?" check here is
 * safe; the index is the backstop.
 */
class LedgerService
{
    /**
     * What the user can spend now: purchases minus charges minus open holds.
     */
    public function available(User $user): int
    {
        return (int) LedgerEntry::query()->where('user_id', $user->id)->sum('amount_nanos');
    }

    /**
     * Credit reserved by generations that haven't finished yet.
     */
    public function held(User $user): int
    {
        $openHolds = LedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('type', LedgerType::Hold)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('ledger_entries as releases')
                ->whereColumn('releases.generation_id', 'ledger_entries.generation_id')
                ->where('releases.type', LedgerType::Release->value))
            ->sum('amount_nanos');

        return -(int) $openHolds;
    }

    /** Buy credits. Fake: no payment is taken. */
    public function purchase(User $user, int $nanos): LedgerEntry
    {
        if ($nanos <= 0) {
            throw new InvalidArgumentException('A purchase must be a positive amount.');
        }

        return LedgerEntry::create([
            'user_id' => $user->id,
            'type' => LedgerType::Purchase,
            'amount_nanos' => $nanos,
        ]);
    }

    /** Reserve the generation's maximum price. */
    public function hold(Generation $generation): void
    {
        $this->write($generation, LedgerType::Hold, -$generation->max_price_nanos);
    }

    /**
     * Give the hold back. Does nothing if it was already released: a
     * generation that gave up as `submission_failed` and later turns out
     * to have succeeded is charged, but its hold was returned long ago.
     */
    public function release(Generation $generation): void
    {
        if ($this->has($generation, LedgerType::Release)) {
            return;
        }

        $this->write($generation, LedgerType::Release, $generation->max_price_nanos);
    }

    /** Charge the final price. At most once per generation. */
    public function charge(Generation $generation, int $priceNanos): void
    {
        if ($priceNanos <= 0) {
            throw new InvalidArgumentException('A charge must be a positive amount.');
        }

        $this->write($generation, LedgerType::Charge, -$priceNanos);
    }

    private function has(Generation $generation, LedgerType $type): bool
    {
        return LedgerEntry::query()
            ->where('generation_id', $generation->id)
            ->where('type', $type)
            ->exists();
    }

    private function write(Generation $generation, LedgerType $type, int $amount): void
    {
        LedgerEntry::create([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'type' => $type,
            'amount_nanos' => $amount,
        ]);
    }
}
