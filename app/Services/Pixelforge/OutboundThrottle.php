<?php

namespace App\Services\Pixelforge;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps us under Pixelforge's 60 requests a minute, which is per account
 * across all keys and counts every authenticated call: submits, retries,
 * reconcile polls, /v1/usage.
 *
 * A sliding 60-second log of send times, shared by web and every worker
 * through the cache, edited under a cache lock. We stop at 55 to leave room
 * for the local key, which shares the same account. Pixelforge's own
 * X-RateLimit-Remaining is honoured too: if it says 0, we pause for a
 * minute (the docs give no reset time).
 */
class OutboundThrottle
{
    public const int LIMIT = 55;

    public const int WINDOW_SECONDS = 60;

    private const string LOG_KEY = 'pixelforge:throttle:sent';

    private const string PAUSE_KEY = 'pixelforge:throttle:paused_until';

    private const string LOCK_KEY = 'pixelforge:throttle:lock';

    /**
     * Claim a slot for one request.
     *
     * @return int 0 if the request may go now, else seconds to wait
     */
    public function reserve(): int
    {
        try {
            return Cache::lock(self::LOCK_KEY, 5)->block(3, function (): int {
                $now = now()->getTimestamp();

                $pausedUntil = (int) Cache::get(self::PAUSE_KEY, 0);
                if ($pausedUntil > $now) {
                    return $pausedUntil - $now;
                }

                /** @var list<int> $sent */
                $sent = array_values(array_filter(
                    (array) Cache::get(self::LOG_KEY, []),
                    fn (mixed $at) => is_int($at) && $at > $now - self::WINDOW_SECONDS,
                ));

                if (count($sent) >= self::LIMIT) {
                    // A slot frees up when the oldest send leaves the window.
                    return max(1, min($sent) + self::WINDOW_SECONDS - $now);
                }

                $sent[] = $now;
                Cache::put(self::LOG_KEY, $sent, self::WINDOW_SECONDS * 2);

                return 0;
            });
        } catch (LockTimeoutException) {
            return 1;
        }
    }

    /** Learn from Pixelforge's own count on each authenticated response. */
    public function observe(Response $response): void
    {
        if ($response->header('X-RateLimit-Remaining') === '0') {
            Cache::put(self::PAUSE_KEY, now()->getTimestamp() + self::WINDOW_SECONDS, self::WINDOW_SECONDS * 2);
        }
    }
}
