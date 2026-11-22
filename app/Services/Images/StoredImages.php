<?php

namespace App\Services\Images;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Short-lived signed links to our stored copies on the private `images`
 * disk.
 *
 * S3 signs every link with the current time, so a fresh link each time
 * History polls (every 3 seconds) would make the browser download every
 * image again. Each link is cached for 8 minutes and valid for 10, so it
 * stays the same between polls and is never handed out expired.
 */
class StoredImages
{
    public const string DISK = 'images';

    public const int LINK_MINUTES = 10;

    public const int CACHE_MINUTES = 8;

    public function url(string $path): string
    {
        /** @var string */
        return Cache::remember(
            "images:url:{$path}",
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => Storage::disk(self::DISK)->temporaryUrl($path, now()->addMinutes(self::LINK_MINUTES)),
        );
    }
}
