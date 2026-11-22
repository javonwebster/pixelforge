<?php

namespace Tests\Feature\Images;

use App\Enums\GenerationStatus;
use App\Jobs\ProcessGeneratedImage;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\GenerationUpdater;
use App\Services\Images\ImageConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class ProcessGeneratedImageTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private const string IMAGE_URL = 'https://pixelforge.promptedit.dev/images/gen_abc.png?expires=1&signature=x';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Storage::fake('images');
        Storage::disk('images')->buildTemporaryUrlsUsing(
            fn (string $path, \DateTimeInterface $expires) => "https://bucket.example/{$path}?expires={$expires->getTimestamp()}",
        );

        // ffmpeg itself is covered by ImageConverterTest; here it just
        // writes recognisable files.
        $this->app->instance(ImageConverter::class, new class extends ImageConverter
        {
            public function toWebp(string $png, string $directory): array
            {
                File::put($directory.'/full.webp', 'FULL:'.File::get($png));
                File::put($directory.'/thumbnail.webp', 'THUMB');

                return ['full' => $directory.'/full.webp', 'thumbnail' => $directory.'/thumbnail.webp'];
            }
        });
    }

    /** @param  array<string, mixed>  $attributes */
    private function succeeded(array $attributes = []): Generation
    {
        return Generation::factory()->for($this->user)->status(GenerationStatus::Succeeded)->create($attributes + [
            'prompt' => 'A lighthouse on a cliff at dusk',
            'image_url' => self::IMAGE_URL,
            'image_url_expires_at' => now()->addHours(20),
            'completed_at' => now()->subMinutes(5),
            'charge_nanos' => 73_825_000,
        ]);
    }

    private function runJob(Generation $generation): void
    {
        app()->call([new ProcessGeneratedImage($generation->id), 'handle']);
    }

    public function test_copies_the_image_and_its_thumbnail_to_the_images_disk(): void
    {
        Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response('PNG', 200)]);
        $generation = $this->succeeded();

        $this->runJob($generation);

        $fresh = $generation->fresh();
        $this->assertSame("generations/{$generation->id}/full.webp", $fresh?->stored_image_path);
        $this->assertSame("generations/{$generation->id}/thumbnail.webp", $fresh?->thumbnail_path);
        Storage::disk('images')->assertExists($fresh->stored_image_path);
        $this->assertSame('FULL:PNG', Storage::disk('images')->get($fresh->stored_image_path));
        $this->assertSame('THUMB', Storage::disk('images')->get($fresh->thumbnail_path));
    }

    public function test_never_touches_money(): void
    {
        Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response('PNG', 200)]);
        $generation = $this->succeeded();
        $before = [$generation->status, $generation->charge_nanos, LedgerEntry::query()->count()];

        $this->runJob($generation);

        $fresh = $generation->fresh();
        $this->assertSame($before, [$fresh?->status, $fresh?->charge_nanos, LedgerEntry::query()->count()]);
    }

    public function test_a_failed_download_saves_nothing_and_throws_so_it_retries(): void
    {
        Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response('', 403)]);
        $generation = $this->succeeded();

        try {
            $this->runJob($generation);
            $this->fail('Expected the download to throw.');
        } catch (RequestException) {
        }

        $this->assertNull($generation->fresh()?->stored_image_path);
        Storage::disk('images')->assertDirectoryEmpty('/');
    }

    public function test_does_nothing_when_already_copied(): void
    {
        Http::fake();
        $generation = $this->succeeded(['stored_image_path' => 'generations/x/full.webp']);

        $this->runJob($generation);

        Http::assertNothingSent();
    }

    public function test_does_nothing_for_an_unfinished_or_failed_generation(): void
    {
        Http::fake();

        $this->runJob(Generation::factory()->for($this->user)->status(GenerationStatus::Processing)->create());
        $this->runJob(Generation::factory()->for($this->user)->status(GenerationStatus::Failed)->create());

        Http::assertNothingSent();
    }

    public function test_an_expired_link_is_logged_not_fetched(): void
    {
        Http::fake();
        Log::spy();

        $this->runJob($this->succeeded(['image_url_expires_at' => now()->subMinute()]));

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->with('Image link expired before it was copied.', \Mockery::any())->once();
    }

    public function test_never_fetches_from_outside_pixelforge(): void
    {
        Http::fake();

        $this->runJob($this->succeeded(['image_url' => 'https://169.254.169.254/latest/meta-data/']));

        Http::assertNothingSent();
        $this->assertNull(Generation::query()->sole()->stored_image_path);
    }

    public function test_is_queued_after_a_success_is_settled(): void
    {
        $this->fakePixelforge();
        $generation = $this->submitGeneration($this->userWithCredit(5_000_000_000));   // fakes the queue

        app(GenerationUpdater::class)->apply($this->pixelforgeObject($generation, 'succeeded'));

        Queue::assertPushed(ProcessGeneratedImage::class, fn (ProcessGeneratedImage $job) => $job->generationId === $generation->id);
    }

    public function test_is_not_queued_for_a_failure(): void
    {
        $this->fakePixelforge();
        $generation = $this->submitGeneration($this->userWithCredit(5_000_000_000));

        app(GenerationUpdater::class)->apply($this->pixelforgeObject($generation, 'failed'));

        Queue::assertNotPushed(ProcessGeneratedImage::class);
    }

    public function test_only_one_copy_job_per_generation_is_queued_at_a_time(): void
    {
        Queue::fake();
        $generation = $this->succeeded();

        ProcessGeneratedImage::dispatch($generation->id);
        ProcessGeneratedImage::dispatch($generation->id);

        Queue::assertPushed(ProcessGeneratedImage::class, 1);
    }

    public function test_reconcile_queues_copies_still_missing(): void
    {
        Queue::fake();
        Http::fake();
        $missing = $this->succeeded();
        $this->succeeded(['completed_at' => now()->subMinute()]);                      // its own job may still be running
        $this->succeeded(['image_url_expires_at' => now()->addMinutes(3)]);            // about to expire
        $this->succeeded(['stored_image_path' => 'generations/x/full.webp']);         // already copied

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        Queue::assertPushed(ProcessGeneratedImage::class, 1);
        Queue::assertPushed(ProcessGeneratedImage::class, fn (ProcessGeneratedImage $job) => $job->generationId === $missing->id);
    }

    public function test_history_shows_our_copy_even_after_pixelforges_link_expires(): void
    {
        $generation = $this->succeeded([
            'image_url_expires_at' => now()->subHour(),
            'stored_image_path' => 'generations/g/full.webp',
            'thumbnail_path' => 'generations/g/thumbnail.webp',
        ]);

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.image.stored', true)
                ->where('generations.data.0.image.expired', false)
                ->where('generations.data.0.image.url', fn (string $url) => str_starts_with($url, 'https://bucket.example/generations/g/thumbnail.webp'))
                ->where('generations.data.0.image.fullUrl', fn (string $url) => str_starts_with($url, 'https://bucket.example/generations/g/full.webp'))
                ->where('generations.data.0.image.downloadUrl', "/generations/{$generation->id}/download"));
    }

    public function test_signed_links_stay_the_same_between_polls(): void
    {
        $this->succeeded(['stored_image_path' => 'generations/g/full.webp', 'thumbnail_path' => 'generations/g/thumbnail.webp']);
        $url = fn () => $this->actingAs($this->user)->get(route('generations.index'))->viewData('page')['props']['generations']['data'][0]['image']['url'];

        $first = $url();
        $this->travel(5)->minutes();
        $this->assertSame($first, $url());

        // Fresh link once the cached one is close to expiring.
        $this->travel(4)->minutes();
        $this->assertNotSame($first, $url());
    }

    public function test_download_serves_our_webp_copy(): void
    {
        Http::fake();
        Storage::disk('images')->put('generations/g/full.webp', 'WEBP-BYTES');
        $generation = $this->succeeded([
            'image_url_expires_at' => now()->subHour(),
            'stored_image_path' => 'generations/g/full.webp',
            'thumbnail_path' => 'generations/g/thumbnail.webp',
        ]);

        $response = $this->actingAs($this->user)->get(route('generations.download', $generation));

        $response->assertOk()->assertDownload('a-lighthouse-on-a-cliff-at-dusk.webp');
        $this->assertSame('WEBP-BYTES', $response->streamedContent());
        Http::assertNothingSent();
    }

    public function test_requests_to_pixelforge_carry_no_api_key(): void
    {
        config(['services.pixelforge.key' => 'pf_secret_key']);
        Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response('PNG', 200)]);

        $this->runJob($this->succeeded());

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
    }
}
