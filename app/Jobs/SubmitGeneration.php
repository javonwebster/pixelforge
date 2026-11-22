<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Enums\PixelforgeErrorCode;
use App\Models\Generation;
use App\Services\GenerationUpdater;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\ThrottledLocallyException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one pending generation to Pixelforge and handles every answer.
 *
 * | Pixelforge answer                    | What happens                         |
 * | ------------------------------------ | ------------------------------------ |
 * | 202 (new or Idempotent-Replayed)     | object goes to GenerationUpdater     |
 * | 429 concurrency / rate limit         | retry after Retry-After              |
 * | 503 overloaded, timeout, no response | retry with backoff, same key         |
 * | 429 daily, 400 content policy        | rejected, hold released              |
 * | 422, 401 (our bug or config)         | rejected, hold released, logged      |
 * | 409 idempotency_key_reused           | submission_failed: it EXISTS there   |
 *
 * Gives up after 5 minutes (PIXELFORGE_SUBMIT_GIVE_UP_MINUTES). If the
 * last answer was a clean refusal, Pixelforge created nothing and the row
 * is `rejected`. Otherwise (timeout, no response) it may exist, so the row
 * is `submission_failed` and reconcile keeps looking for it.
 *
 * The worker runs with --tries=0: retryUntil() alone decides when to stop.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Pixelforge allows 3 queued or processing per account. */
    public const int MAX_IN_FLIGHT = 3;

    public const int DEFAULT_RETRY_AFTER = 5;

    public const int MAX_BACKOFF = 60;

    public int $timeout = 60;

    public function __construct(public readonly string $generationId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('services.pixelforge.submit_give_up_minutes', 5));
    }

    public function handle(PixelforgeClient $client, GenerationUpdater $updater): void
    {
        $generation = Generation::query()->find($this->generationId);

        // Already accepted (a webhook or reconcile may have got there
        // first) or closed. Nothing to send.
        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        // Don't spend a request on a sure 429: wait for a slot locally.
        if ($this->inFlight() >= self::MAX_IN_FLIGHT) {
            $updater->recordSubmitError($generation, PixelforgeErrorCode::ConcurrencyLimitExceeded, fromPixelforge: false);
            $this->release(self::DEFAULT_RETRY_AFTER);

            return;
        }

        try {
            $object = $client->createGeneration($generation);
        } catch (ThrottledLocallyException $e) {
            $updater->recordSubmitError($generation, PixelforgeErrorCode::RateLimitExceeded, fromPixelforge: false);
            $this->release($e->retryAfter);

            return;
        } catch (ConnectionException) {
            // Timeout or no connection: Pixelforge may or may not have it.
            // The same Idempotency-Key makes retrying safe either way.
            $updater->recordSubmitError($generation, PixelforgeErrorCode::ConnectionError);
            $this->release($this->backoffDelay());

            return;
        } catch (PixelforgeApiException $e) {
            $this->handleRefusal($generation, $e, $updater);

            return;
        }

        $updater->apply($object);
    }

    /**
     * Called by the queue once retryUntil() has passed.
     */
    public function failed(?Throwable $exception): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        $updater = app(GenerationUpdater::class);
        $last = PixelforgeErrorCode::tryFrom((string) $generation->last_submit_error);
        $message = 'Gave up after the retry window. Last error: '.($last->value ?? 'none').'.';

        if ($last?->createdNothing()) {
            $updater->reject($generation, $last, $message);
        } else {
            $updater->markSubmissionFailed($generation, $last ?? PixelforgeErrorCode::ConnectionError, $message);
        }
    }

    private function handleRefusal(Generation $generation, PixelforgeApiException $e, GenerationUpdater $updater): void
    {
        $code = $e->errorCode;

        match ($code) {
            PixelforgeErrorCode::ConcurrencyLimitExceeded,
            PixelforgeErrorCode::RateLimitExceeded => $this->retryLater($generation, $code, $updater, $e->retryAfter ?? self::DEFAULT_RETRY_AFTER),

            PixelforgeErrorCode::DailyLimitExceeded,
            PixelforgeErrorCode::ContentPolicyViolation => $updater->reject($generation, $code, $e->getMessage()),

            PixelforgeErrorCode::ValidationFailed,
            PixelforgeErrorCode::InvalidApiKey => $this->rejectAsBug($generation, $e, $updater),

            PixelforgeErrorCode::IdempotencyKeyReused => $this->keyReused($generation, $e, $updater),

            // 503 overloaded, or an answer we don't recognise (a proxy's
            // 502, say): retry with backoff until the window closes.
            default => $this->retryLater($generation, $code, $updater, $this->backoffDelay()),
        };
    }

    private function retryLater(Generation $generation, PixelforgeErrorCode $code, GenerationUpdater $updater, int $delay): void
    {
        $updater->recordSubmitError($generation, $code);
        $this->release($delay);
    }

    /** A 422 or 401 means our validation or config is wrong, not the user's request. */
    private function rejectAsBug(Generation $generation, PixelforgeApiException $e, GenerationUpdater $updater): void
    {
        Log::error('Pixelforge refused a submit we should never send.', [
            'generation' => $generation->id,
            'status' => $e->status,
            'code' => $e->errorCode->value,
            'message' => $e->getMessage(),
        ]);

        $updater->reject($generation, $e->errorCode, $e->getMessage());
    }

    /**
     * Can't happen while the body is built the same way from the stored
     * row. If it does, a generation with this key exists at Pixelforge.
     */
    private function keyReused(Generation $generation, PixelforgeApiException $e, GenerationUpdater $updater): void
    {
        Log::critical('Pixelforge idempotency key reused with a different body.', ['generation' => $generation->id]);

        $updater->markSubmissionFailed($generation, $e->errorCode, $e->getMessage());
    }

    /** 2, 4, 8 … seconds, capped at a minute. */
    private function backoffDelay(): int
    {
        return min(self::MAX_BACKOFF, 2 ** max(1, $this->attempts()));
    }

    private function inFlight(): int
    {
        return Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->count();
    }
}
