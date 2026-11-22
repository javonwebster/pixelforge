<?php

namespace App\Services\Images;

use Illuminate\Support\Facades\Process;

/**
 * Turns Pixelforge's PNG into a full-size WebP and a 384px-wide thumbnail
 * with ffmpeg, which is installed in the queue worker's image.
 */
class ImageConverter
{
    public const int THUMBNAIL_WIDTH = 384;

    /**
     * @return array{full: string, thumbnail: string} paths of the new files
     */
    public function toWebp(string $png, string $directory): array
    {
        $full = $directory.'/full.webp';
        $thumbnail = $directory.'/thumbnail.webp';

        $this->ffmpeg([$png], ['-c:v', 'libwebp', '-quality', '85'], $full);
        // scale=384:-2 keeps the aspect ratio with an even height.
        $this->ffmpeg([$png], ['-vf', 'scale='.self::THUMBNAIL_WIDTH.':-2', '-c:v', 'libwebp', '-quality', '80'], $thumbnail);

        return ['full' => $full, 'thumbnail' => $thumbnail];
    }

    /**
     * @param  list<string>  $inputs
     * @param  list<string>  $options
     */
    private function ffmpeg(array $inputs, array $options, string $output): void
    {
        $command = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y'];

        foreach ($inputs as $input) {
            array_push($command, '-i', $input);
        }

        // An array command: no shell, so nothing in a path is interpreted.
        Process::timeout(60)->run([...$command, ...$options, '-map_metadata', '-1', $output])->throw();
    }
}
