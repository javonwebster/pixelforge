<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationUpdater;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\ThrottledLocallyException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook delivery isn't guaranteed, so every minute this catches up:
 *
 * 1. Re-applies stored webhook events that matched no generation yet
 *    (no API calls).
 * 2. Polls queued and processing generations with no news for 60 seconds.
 * 3. Looks for `submission_failed` generations from the last 24 hours in
 *    one GET /v1/generations call, matching metadata.local_id. Read-only,
 *    so it can't create anything; if one turns up succeeded, it's billed.
 *
 * Everything goes through GenerationUpdater, the same path as webhooks, so
 * a poll and a webhook for the same result can't charge twice. Polls are
 * capped per run and go through the shared throttle, leaving most of the
 * 60-a-minute budget for submits.
 */
#[Signature('pixelforge:reconcile')]
#[Description('Catch up on generations whose webhooks may not have arrived')]
class ReconcileGenerations extends Command
{
    /** Quiet this long before a generation is polled. */
    public const int STALE_SECONDS = 60;

    /** Most polls per run: a fraction of the 55-a-minute budget. */
    public const int MAX_POLLS = 20;

    /** How long to keep looking for an unclear submit. */
    public const int LOOKBACK_HOURS = 24;

    public function handle(PixelforgeClient $client, GenerationUpdater $updater): int
    {
        $events = $this->reprocessUnmatchedEvents($updater);

        try {
            $polled = $this->pollStale($client, $updater);
            $found = $this->findSubmissionFailures($client, $updater);
        } catch (ThrottledLocallyException $e) {
            $this->warn("Request budget used up; the rest waits for the next run ({$e->retryAfter}s).");

            return self::SUCCESS;
        }

        $this->info("Reconciled: {$events} webhook event(s) applied, {$polled} generation(s) polled, {$found} unclear submit(s) found.");

        return self::SUCCESS;
    }

    private function reprocessUnmatchedEvents(GenerationUpdater $updater): int
    {
        $applied = 0;

        $ids = WebhookEvent::query()
            ->whereNull('processed_at')
            ->where('received_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        foreach ($ids as $id) {
            $applied += DB::transaction(function () use ($id, $updater): int {
                $event = WebhookEvent::query()->whereKey($id)->whereNull('processed_at')->lockForUpdate()->first();
                $object = $event?->generationObject();

                if ($event === null || ($object !== null && $updater->apply($object) === null)) {
                    return 0;
                }

                $event->update(['processed_at' => now()]);

                return 1;
            });
        }

        return $applied;
    }

    private function pollStale(PixelforgeClient $client, GenerationUpdater $updater): int
    {
        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<=', now()->subSeconds(self::STALE_SECONDS))
            ->orderBy('updated_at')
            ->limit(self::MAX_POLLS)
            ->get();

        foreach ($stale as $generation) {
            try {
                // apply() touches the row even when nothing changed, so it
                // drops to the back of the queue for the next run.
                $updater->apply($client->getGeneration((string) $generation->pixelforge_id));
            } catch (PixelforgeApiException|ConnectionException $e) {
                Log::warning('Reconcile poll failed.', ['generation' => $generation->id, 'error' => $e->getMessage()]);
            }
        }

        return $stale->count();
    }

    private function findSubmissionFailures(PixelforgeClient $client, GenerationUpdater $updater): int
    {
        $unclear = Generation::query()
            ->where('status', GenerationStatus::SubmissionFailed)
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->pluck('id')
            ->flip();

        if ($unclear->isEmpty()) {
            return 0;
        }

        try {
            $recent = $client->recentGenerations(100);
        } catch (PixelforgeApiException|ConnectionException $e) {
            Log::warning('Reconcile list failed.', ['error' => $e->getMessage()]);

            return 0;
        }

        $found = 0;

        foreach ($recent as $object) {
            $localId = data_get($object, 'metadata.local_id');

            if (is_string($localId) && $unclear->has($localId)) {
                $updater->apply($object);
                $found++;
            }
        }

        return $found;
    }
}
