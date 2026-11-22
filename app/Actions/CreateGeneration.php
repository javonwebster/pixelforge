<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Exceptions\InsufficientBalanceException;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Pricing\PricingCalculator;
use App\Services\Pricing\PricingCatalog;
use Illuminate\Support\Facades\DB;

/**
 * The submit path: price the request, reserve the maximum, create the row,
 * and queue the call to Pixelforge. The page never waits on Pixelforge.
 *
 * Also used by the automatic retry of `internal_error`, which passes the
 * original as $retryOf and runs inside the updater's transaction.
 */
class CreateGeneration
{
    public function __construct(
        private readonly PricingCalculator $calculator,
        private readonly PricingCatalog $catalog,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     *
     * @throws InsufficientBalanceException and nothing is created
     */
    public function handle(
        User $user,
        string $prompt,
        Size $size,
        Quality $quality,
        array $referenceImages = [],
        ?Generation $retryOf = null,
    ): Generation {
        // Priced before the transaction: fetching rates can mean an HTTP
        // call, which shouldn't happen while holding the user's row lock.
        $quote = $this->calculator->quote($prompt, $size, $quality, count($referenceImages), $this->catalog->currentRates());

        return DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $retryOf, $quote) {
            // Lock the user's row so two submits at once can't both pass
            // the balance check. The second waits here until the first
            // commits its hold, then sees the lower balance.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $available = $this->ledger->available($user);

            if ($available < $quote->maximumPrice) {
                throw new InsufficientBalanceException($quote->maximumPrice, $available);
            }

            $generation = Generation::create([
                'user_id' => $user->id,
                'retry_of_id' => $retryOf?->id,
                'status' => GenerationStatus::Pending,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'estimated_price_nanos' => $quote->estimatedPrice,
                'max_price_nanos' => $quote->maximumPrice,
            ]);

            $this->ledger->hold($generation);

            // After commit, so the worker never picks up a row that a
            // rollback is about to remove.
            SubmitGeneration::dispatch($generation->id)->afterCommit();

            return $generation;
        });
    }
}
