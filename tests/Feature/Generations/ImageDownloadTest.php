<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ImageDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const string IMAGE_URL = 'https://pixelforge.promptedit.dev/images/gen_abc.png?expires=1&signature=x';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** @param  array<string, mixed>  $attributes */
    private function succeeded(array $attributes = []): Generation
    {
        return Generation::factory()->for($this->user)->status(GenerationStatus::Succeeded)->create($attributes + [
            'prompt' => 'A lighthouse on a cliff at dusk, watercolour',
            'image_url' => self::IMAGE_URL,
            'image_url_expires_at' => now()->addHours(20),
        ]);
    }

    public function test_downloads_the_image_named_after_the_prompt(): void
    {
        Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response('PNG-BYTES', 200, ['Content-Type' => 'image/png'])]);
        $generation = $this->succeeded();

        $response = $this->actingAs($this->user)->get(route('generations.download', $generation));

        $response->assertOk()
            ->assertDownload('a-lighthouse-on-a-cliff-at-dusk-watercolour.png')
            ->assertHeader('Content-Type', 'image/png');
        $this->assertSame('PNG-BYTES', $response->streamedContent());
    }

    public function test_history_offers_open_and_download_for_a_live_image(): void
    {
        $generation = $this->succeeded();

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.image.url', self::IMAGE_URL)
                ->where('generations.data.0.image.downloadUrl', "/generations/{$generation->id}/download"));
    }

    public function test_no_download_is_offered_once_the_image_has_expired(): void
    {
        $this->succeeded(['image_url_expires_at' => now()->subMinute()]);

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('generations.data.0.image.downloadUrl', null));
    }

    public function test_an_expired_image_is_gone(): void
    {
        Http::fake();
        $generation = $this->succeeded(['image_url_expires_at' => now()->subMinute()]);

        $this->actingAs($this->user)->get(route('generations.download', $generation))->assertStatus(410);

        Http::assertNothingSent();
    }

    public function test_another_users_image_is_not_found(): void
    {
        Http::fake();
        $theirs = Generation::factory()->status(GenerationStatus::Succeeded)->create(['image_url' => self::IMAGE_URL]);

        $this->actingAs($this->user)->get(route('generations.download', $theirs))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_a_malformed_id_is_not_found(): void
    {
        $this->actingAs($this->user)->get('/generations/not-a-uuid/download')->assertNotFound();
    }

    public function test_an_unfinished_generation_has_nothing_to_download(): void
    {
        $generation = Generation::factory()->for($this->user)->create();

        $this->actingAs($this->user)->get(route('generations.download', $generation))->assertNotFound();
    }

    public function test_never_fetches_from_anywhere_but_pixelforge(): void
    {
        Http::fake();
        $generation = $this->succeeded(['image_url' => 'http://169.254.169.254/latest/meta-data/']);

        $this->actingAs($this->user)->get(route('generations.download', $generation))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_pixelforge_failing_is_a_bad_gateway(): void
    {
        Http::fake(['pixelforge.promptedit.dev/*' => Http::response('', 403)]);
        $generation = $this->succeeded();

        $this->actingAs($this->user)->get(route('generations.download', $generation))->assertStatus(502);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('generations.download', $this->succeeded()))->assertRedirect(route('login'));
    }
}
