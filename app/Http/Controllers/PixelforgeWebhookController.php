<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\GenerationUpdater;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/pixelforge. The signature is already verified by
 * VerifyPixelforgeSignature.
 *
 * Pixelforge delivers at least once and possibly out of order, and treats
 * any 2xx within 10 seconds as delivered. So, in ONE transaction:
 *
 *   1. insert the event (unique event_id)
 *   2. hand data.object to GenerationUpdater
 *   3. commit
 *
 * A duplicate fails step 1 on the unique index and gets a 200 with nothing
 * else done. If step 2 throws, step 1 rolls back with it, we answer 500,
 * and Pixelforge's retry is processed as new. With two separate steps, a
 * crash between them would record the event without applying it, and the
 * retry would then be thrown away as a duplicate.
 *
 * @see https://pixelforge.promptedit.dev/docs
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationUpdater $updater): JsonResponse
    {
        $event = json_decode($request->getContent(), true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response()->json(['error' => 'malformed_event'], 400);
        }

        $status = DB::transaction(function () use ($event, $request, $updater): string {
            try {
                // Its own savepoint, so a duplicate rolls back only this
                // insert. Catching the violation here, and nowhere else,
                // means a unique violation from inside the updater (the
                // ledger index, say) is never mistaken for a duplicate.
                $stored = DB::transaction(fn () => WebhookEvent::create([
                    'event_id' => $event['id'],
                    'type' => $event['type'],
                    'pixelforge_generation_id' => is_string(data_get($event, 'data.object.id')) ? data_get($event, 'data.object.id') : null,
                    'delivery_attempt' => ctype_digit((string) $request->header('Pixelforge-Delivery-Attempt'))
                        ? (int) $request->header('Pixelforge-Delivery-Attempt')
                        : null,
                    'payload' => $event,
                    'received_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                // Seen before: answer 200 so Pixelforge stops retrying. The
                // log line is the only trace a duplicate leaves.
                Log::info('Duplicate Pixelforge webhook ignored.', [
                    'event_id' => $event['id'],
                    'type' => $event['type'],
                    'delivery_attempt' => $request->header('Pixelforge-Delivery-Attempt'),
                ]);

                return 'duplicate';
            }

            $object = $stored->generationObject();

            // ping and any type we don't act on: recorded, nothing to apply.
            if ($object === null) {
                $stored->update(['processed_at' => now()]);

                return 'recorded';
            }

            // No local match yet: keep it unprocessed for reconcile.
            if ($updater->apply($object) === null) {
                return 'unmatched';
            }

            $stored->update(['processed_at' => now()]);

            return 'applied';
        });

        return response()->json(['status' => $status]);
    }
}
