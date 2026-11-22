<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Images\ImageConverter;
use App\Services\Images\StoredImages;
use App\Services\Pixelforge\PixelforgeImageUrl;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keeps our own copy of a generated image, because Pixelforge's link stops
 * working 24 hours after the generation completes.
 *
 * Downloads the PNG while the link is valid, converts it with ffmpeg to a
 * full-size WebP and a thumbnail, stores both on the private `images` disk
 * (S3 in production) and records the paths. History and downloads then use
 * our copy.
 *
 * Never touches money: it runs after the charge has committed, and if it
 * fails it simply retries. Reconcile re-queues any succeeded generation
 * still missing a copy, so a job that gives up gets another chance.
 */
class ProcessGeneratedImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 600];

    public int $timeout = 120;

    /** One copy job per generation at a time, however often it's queued. */
    public int $uniqueFor = 900;

    public function __construct(public readonly string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    public function handle(ImageConverter $converter): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null
            || $generation->status !== GenerationStatus::Succeeded
            || $generation->stored_image_path !== null
            || $generation->image_url === null) {
            return;
        }

        if ($generation->image_url_expires_at?->isPast()) {
            Log::warning('Image link expired before it was copied.', ['generation' => $generation->id]);

            return;
        }

        if (! PixelforgeImageUrl::isTrusted($generation->image_url)) {
            Log::warning('Refusing to copy an image from outside Pixelforge.', ['generation' => $generation->id]);

            return;
        }

        $directory = sys_get_temp_dir().'/pixelforge-'.Str::uuid();
        File::ensureDirectoryExists($directory);

        try {
            $png = $directory.'/source.png';
            Http::sink($png)->connectTimeout(5)->timeout(60)->get($generation->image_url)->throw();

            $files = $converter->toWebp($png, $directory);
            $paths = [
                'stored_image_path' => "generations/{$generation->id}/full.webp",
                'thumbnail_path' => "generations/{$generation->id}/thumbnail.webp",
            ];

            $disk = Storage::disk(StoredImages::DISK);
            $disk->put($paths['stored_image_path'], File::get($files['full']));
            $disk->put($paths['thumbnail_path'], File::get($files['thumbnail']));

            // Only these two columns: status and money belong to the updater.
            Generation::query()->whereKey($generation->id)->whereNull('stored_image_path')->update($paths);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
