<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a webhook through only if Pixelforge signed it.
 *
 * Pixelforge-Signature is `t=<unix time>,v1=<hex>`, where v1 is the
 * HMAC-SHA256 of `<t>.<raw body>` keyed with this API key's `whsec_`
 * secret. The timestamp must be within 5 minutes either way, so a captured
 * delivery can't be replayed later. Anything else gets a 400 and nothing is
 * stored.
 *
 * @see https://pixelforge.promptedit.dev/docs
 */
class VerifyPixelforgeSignature
{
    public const int TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.pixelforge.webhook_secret');

        if ($secret === '') {
            Log::critical('PIXELFORGE_WEBHOOK_SECRET is not set; every webhook is being refused.');

            return $this->reject();
        }

        [$timestamp, $signatures] = $this->parse((string) $request->header('Pixelforge-Signature'));

        if ($timestamp === null || $signatures === []) {
            return $this->reject();
        }

        if (abs(now()->getTimestamp() - $timestamp) > self::TOLERANCE_SECONDS) {
            return $this->reject();
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            // Constant-time: a timing difference must not leak the signature.
            if (hash_equals($expected, $signature)) {
                return $next($request);
            }
        }

        return $this->reject();
    }

    /**
     * @return array{0: int|null, 1: list<string>}
     */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }

    private function reject(): Response
    {
        return response()->json(['error' => 'invalid_signature'], 400);
    }
}
