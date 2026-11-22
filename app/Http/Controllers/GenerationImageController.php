<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Images\StoredImages;
use App\Services\Pixelforge\PixelforgeImageUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a generated image as a file named after its prompt.
 *
 * A plain link can't do this: browsers ignore the `download` attribute on
 * links to another site. So the file comes through here: our stored WebP
 * when the image job has copied it (it never expires), otherwise the PNG
 * from Pixelforge while its signed link is still valid (24 hours).
 */
class GenerationImageController extends Controller
{
    public function download(Request $request, Generation $generation): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Someone else's generation looks the same as one that doesn't exist.
        abort_unless($generation->user_id === $user->id, 404);
        abort_unless($generation->status === GenerationStatus::Succeeded, 404);

        if ($generation->stored_image_path !== null) {
            return Storage::disk(StoredImages::DISK)->download(
                $generation->stored_image_path,
                self::filename($generation, 'webp'),
                ['Content-Type' => 'image/webp'],
            );
        }

        abort_if($generation->image_url === null, 404);
        abort_if($generation->image_url_expires_at?->isPast(), 410, 'This image has expired.');

        // Only ever fetch from Pixelforge's own host, so a bad URL in the
        // database can't make this server request arbitrary addresses.
        abort_unless(PixelforgeImageUrl::isTrusted($generation->image_url), 404);

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
        }, self::filename($generation, 'png'), [
            'Content-Type' => 'image/png',
        ]);
    }

    /** "a-lighthouse-on-a-cliff-at-dusk.png", from the prompt. */
    public static function filename(Generation $generation, string $extension): string
    {
        $slug = Str::slug(Str::limit($generation->prompt, 50, ''));

        return ($slug !== '' ? $slug : 'pixelforge-image').'.'.$extension;
    }
}
