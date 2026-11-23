<?php

namespace App\Services;

use App\Actions\CreateGeneration;
use App\Enums\GenerationStatus;
use App\Enums\PixelforgeErrorCode;
use App\Exceptions\InsufficientBalanceException;
use App\Jobs\ProcessGeneratedImage;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pricing\PricingCalculator;
use App\Services\Pricing\Rates;
use App\Services\Pricing\TokenCounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Applies every status change after creation, whatever its source: the
 * submit response (including idempotent replays, which return the
 * generation as it is now), webhooks, and the reconcile poller.
 *
 * Each change runs in a transaction holding the generation's row lock, so
 * two deliveries of the same event are applied one after the other. A
 * status only moves forward by rank and a terminal status never changes,
 * so a late or duplicate event can't undo a result or charge twice. The
 * ledger's unique (generation_id, type) index is the backstop.
 *
 * @see https://pixelforge.promptedit.dev/docs
 */
class GenerationUpdater
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly PricingCalculator $calculator,
        private readonly CreateGeneration $createGeneration,
    ) {}

    /**
     * Apply a Pixelforge generation object.
     *
     * @param  array<string, mixed>  $object
     * @return Generation|null null if it matches no local generation
     *
     * @throws UnexpectedValueException if a success can't be billed; the
     *                                  transaction rolls back and the
     *                                  source can retry
     */
    public function apply(array $object): ?Generation
    {
        return DB::transaction(function () use ($object): ?Generation {
            $generation = $this->lockMatching($object);

            if ($generation === null) {
                return null;
            }

            $this->fillMissing($generation, $object);

            $incoming = GenerationStatus::tryFrom(is_string($object['status'] ?? null) ? $object['status'] : '');

            if ($incoming === null || ! in_array($incoming, GenerationStatus::fromPixelforge(), true)) {
                Log::warning('Ignoring unknown Pixelforge status.', ['generation' => $generation->id, 'status' => $object['status'] ?? null]);
            } elseif ($generation->status->canAdvanceTo($incoming)) {
                match ($incoming) {
                    GenerationStatus::Succeeded => $this->settleSuccess($generation, $object),
                    GenerationStatus::Failed => $this->settleFailure($generation, $object),
                    default => $generation->status = $incoming,
                };
            }

            // touch(), not save(): it writes even when nothing else changed,
            // so a poll with no news still counts as fresh and reconcile
            // moves on to other generations.
            $generation->last_synced_at = now();
            $generation->touch();

            return $generation;
        });
    }

    /**
     * Remember why the last submit attempt didn't go through, for the
     * status label and for deciding how to give up.
     *
     * Pixelforge's answers always replace the last error. Our own waits
     * (local throttle, local in-flight count) only fill an empty one, so
     * they never hide an earlier timeout whose outcome is still unknown.
     *
     * Anything but a clean refusal (a timeout, no connection, an answer we
     * don't recognise) also sets `outcome_unknown`, which nothing clears:
     * Pixelforge may have the generation even if every later attempt is a
     * clean 503 or 429.
     */
    public function recordSubmitError(Generation $generation, PixelforgeErrorCode $code, bool $fromPixelforge = true): void
    {
        Generation::query()
            ->whereKey($generation->id)
            ->where('status', GenerationStatus::Pending)
            ->when(! $fromPixelforge, fn ($query) => $query->whereNull('last_submit_error'))
            ->update([
                'last_submit_error' => $code->value,
                ...($code->createdNothing() ? [] : ['outcome_unknown' => true]),
            ]);
    }

    /**
     * Pixelforge definitely created nothing. Free, hold released.
     * Only the submit job calls this, and only a pending row changes.
     */
    public function reject(Generation $generation, PixelforgeErrorCode $code, string $message): void
    {
        $this->closeSubmission($generation, GenerationStatus::Rejected, $code, $message);
    }

    /**
     * The submit job gave up without knowing whether Pixelforge created the
     * generation. Hold released; reconcile keeps looking for it, and if it
     * turns up succeeded it's billed then.
     */
    public function markSubmissionFailed(Generation $generation, PixelforgeErrorCode $code, string $message): void
    {
        $this->closeSubmission($generation, GenerationStatus::SubmissionFailed, $code, $message);
    }

    private function closeSubmission(Generation $generation, GenerationStatus $status, PixelforgeErrorCode $code, string $message): void
    {
        DB::transaction(function () use ($generation, $status, $code, $message): void {
            $locked = Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            // A webhook may have moved it on in the meantime; that wins.
            if ($locked->status !== GenerationStatus::Pending) {
                return;
            }

            $locked->status = $status;
            $locked->error_code = $code->value;
            $locked->error_message = $message;
            $locked->provider_cost_nanos = 0;
            $locked->charge_nanos = 0;
            $locked->save();

            $this->ledger->release($locked);
        });
    }

    /**
     * Find and lock the local row. By metadata.local_id first, because a
     * webhook can arrive before the POST response has given us the
     * Pixelforge id; by Pixelforge id otherwise.
     *
     * @param  array<string, mixed>  $object
     */
    private function lockMatching(array $object): ?Generation
    {
        $pixelforgeId = is_string($object['id'] ?? null) ? $object['id'] : null;
        $localId = data_get($object, 'metadata.local_id');
        $generation = null;

        if (is_string($localId) && Str::isUuid($localId)) {
            $generation = Generation::query()->whereKey($localId)->lockForUpdate()->first();
        }

        if ($generation === null && $pixelforgeId !== null) {
            $generation = Generation::query()->where('pixelforge_id', $pixelforgeId)->lockForUpdate()->first();
        }

        // One local row per Pixelforge generation. A different id means
        // this event belongs to another generation; never let it touch
        // this row's status or money.
        if ($generation?->pixelforge_id !== null && $generation->pixelforge_id !== $pixelforgeId) {
            Log::warning('Pixelforge id mismatch; event ignored.', [
                'generation' => $generation->id,
                'stored' => $generation->pixelforge_id,
                'incoming' => $pixelforgeId,
            ]);

            return null;
        }

        return $generation;
    }

    /**
     * Whichever source arrives first fills these in; later ones don't
     * overwrite them.
     *
     * @param  array<string, mixed>  $object
     */
    private function fillMissing(Generation $generation, array $object): void
    {
        $generation->pixelforge_id ??= is_string($object['id'] ?? null) ? $object['id'] : null;

        if ($generation->rates === null && is_array($object['rates'] ?? null)) {
            $generation->rates = Rates::fromArray($object['rates'])->toArray();
        }

        $generation->submitted_at ??= self::timestamp($object['created_at'] ?? null);
        $generation->completed_at ??= self::timestamp($object['completed_at'] ?? null);
        $generation->image_url ??= is_string($object['image_url'] ?? null) ? $object['image_url'] : null;
        $generation->image_url_expires_at ??= self::timestamp($object['image_url_expires_at'] ?? null);

        if ($generation->output_tokens === null && is_array($object['usage'] ?? null)) {
            $usage = TokenCounts::fromUsage($object['usage']);
            $generation->input_text_tokens = $usage->inputText;
            $generation->input_image_tokens = $usage->inputImage;
            $generation->output_tokens = $usage->output;
            $generation->reasoning_tokens = $usage->reasoning;
        }
    }

    /**
     * Billed: release the hold, charge the actual price at the rates fixed
     * on this generation.
     *
     * @param  array<string, mixed>  $object
     */
    private function settleSuccess(Generation $generation, array $object): void
    {
        if (! is_array($object['usage'] ?? null) || $generation->rates === null) {
            throw new UnexpectedValueException("Succeeded generation {$generation->id} has no usage or rates; can't bill it.");
        }

        $cost = $this->calculator->cost(TokenCounts::fromUsage($object['usage']), Rates::fromArray($generation->rates));
        $price = $this->calculator->price($cost);

        if ($price > $generation->max_price_nanos) {
            // Shouldn't happen: the maximum is the documented upper bound.
            // Charge what Pixelforge billed anyway; the balance may dip
            // slightly below zero.
            Log::warning('Actual price above the hold.', [
                'generation' => $generation->id,
                'price_nanos' => $price,
                'max_price_nanos' => $generation->max_price_nanos,
            ]);
        }

        $generation->status = GenerationStatus::Succeeded;
        $generation->provider_cost_nanos = $cost;
        $generation->charge_nanos = $price;
        $generation->error_code = null;
        $generation->error_message = null;

        $this->ledger->release($generation);
        $this->ledger->charge($generation, $price);

        // Copy the image before Pixelforge's link expires. After commit:
        // the money is settled first, and the copy can never undo it.
        ProcessGeneratedImage::dispatch($generation->id)->afterCommit();
    }

    /**
     * Not billed: release the hold, record why.
     *
     * @param  array<string, mixed>  $object
     */
    private function settleFailure(Generation $generation, array $object): void
    {
        $generation->status = GenerationStatus::Failed;
        $generation->error_code = is_string(data_get($object, 'error.code')) ? data_get($object, 'error.code') : null;
        $generation->error_message = is_string(data_get($object, 'error.message')) ? data_get($object, 'error.message') : null;
        $generation->provider_cost_nanos = 0;
        $generation->charge_nanos = 0;

        $this->ledger->release($generation);

        if ($generation->error_code === PixelforgeErrorCode::InternalError->value && $generation->retry_of_id === null) {
            $this->retryAutomatically($generation);
        }
    }

    /**
     * Pixelforge says `internal_error` is on its side and usually fixed by
     * retrying, and failed generations are never billed, so one automatic
     * retry can't double-charge. `generation_failed` is never retried: the
     * same prompt would fail again.
     *
     * The retry is a NEW row linked by retry_of_id, with its own hold and
     * its own Idempotency-Key. Reusing the failed row would let a late
     * duplicate webhook from the first attempt (matched by local_id) fail
     * the retry. Runs in this transaction, under the failed row's lock, so
     * a duplicate failed event can't start a second retry; a partial
     * unique index on retry_of_id backs that up.
     */
    private function retryAutomatically(Generation $failed): void
    {
        // Deleted accounts don't get new generations.
        $user = User::query()->find($failed->user_id);

        if ($user === null) {
            return;
        }

        try {
            $this->createGeneration->handle(
                $user,
                $failed->prompt,
                $failed->size,
                $failed->quality,
                $failed->reference_images,
                retryOf: $failed,
                rates: $failed->rates !== null ? Rates::fromArray($failed->rates) : null,
            );
        } catch (InsufficientBalanceException) {
            // No retry; the failed row shows "Try again" as usual.
            Log::info('No automatic retry: balance too low.', ['generation' => $failed->id]);
        }
    }

    /** Pixelforge timestamps are ISO 8601 in UTC; stored as such. */
    private static function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
