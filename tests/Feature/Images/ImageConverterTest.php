<?php

namespace Tests\Feature\Images;

use App\Services\Images\ImageConverter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * Runs the real ffmpeg (CI installs it; the production image has it).
 */
class ImageConverterTest extends TestCase
{
    public function test_converts_a_png_to_a_webp_and_a_384px_thumbnail(): void
    {
        if ((new ExecutableFinder)->find('ffmpeg') === null) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }

        $directory = sys_get_temp_dir().'/pixelforge-test-'.Str::uuid();
        File::ensureDirectoryExists($directory);

        try {
            $files = (new ImageConverter)->toWebp(base_path('tests/Fixtures/generated.png'), $directory);

            foreach ($files as $file) {
                $bytes = File::get($file);
                $this->assertSame('RIFF', substr($bytes, 0, 4));
                $this->assertSame('WEBP', substr($bytes, 8, 4));
            }

            // The fixture is 32×24; the thumbnail is 384 wide, same ratio.
            [$width, $height] = getimagesize($files['thumbnail']) ?: [0, 0];
            $this->assertSame([384, 288], [$width, $height]);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
