<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a generated image as a file named after its prompt.
 *
 * A plain link can't do this: browsers ignore the `download` attribute on
 * links to another site, so they'd just open Pixelforge's image. The file
 * is streamed through here instead, while Pixelforge's signed link is
 * still valid (24 hours). The image job will switch this to our stored
 * copy in S3.
 */
class GenerationImageController extends Controller
{
    public function download(Request $request, Generation $generation): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Someone else's generation looks the same as one that doesn't exist.
        abort_unless($generation->user_id === $user->id, 404);
        abort_unless($generation->status === GenerationStatus::Succeeded && $generation->image_url !== null, 404);
        abort_if($generation->image_url_expires_at?->isPast(), 410, 'This image has expired.');

        // Only ever fetch from Pixelforge's own host, so a bad URL in the
        // database can't make this server request arbitrary addresses.
        abort_unless(self::isPixelforgeUrl($generation->image_url), 404);

        try {
            $upstream = Http::withOptions(['stream' => true])
                ->connectTimeout(5)
                ->timeout(30)
                ->get($generation->image_url);
        } catch (ConnectionException) {
            abort(502, "Couldn't fetch the image from Pixelforge.");
        }

        abort_unless($upstream->successful(), 502, "Couldn't fetch the image from Pixelforge.");

        $body = $upstream->toPsrResponse()->getBody();

        return response()->streamDownload(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(64 * 1024);
            }
        }, self::filename($generation), [
            'Content-Type' => 'image/png',
        ]);
    }

    /** "a-lighthouse-on-a-cliff-at-dusk.png", from the prompt. */
    public static function filename(Generation $generation): string
    {
        $slug = Str::slug(Str::limit($generation->prompt, 50, ''));

        return ($slug !== '' ? $slug : 'pixelforge-image').'.png';
    }

    private static function isPixelforgeUrl(string $url): bool
    {
        $allowed = parse_url((string) config('services.pixelforge.base_url'), PHP_URL_HOST);

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && is_string($allowed)
            && parse_url($url, PHP_URL_HOST) === $allowed;
    }
}
