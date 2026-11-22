<?php

namespace Tests\Feature\Webhooks;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class WebhookSignatureTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePixelforge();
        $this->generation = $this->submitGeneration($this->userWithCredit(5_000_000_000));
    }

    private function assertNothingStored(): void
    {
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame(GenerationStatus::Pending, $this->generation->fresh()?->status);
    }

    public function test_valid_signature_is_accepted_and_applied(): void
    {
        $this->postWebhook($this->webhookEvent($this->generation, 'processing'))
            ->assertOk()
            ->assertJson(['status' => 'applied']);

        $this->assertSame(GenerationStatus::Processing, $this->generation->fresh()?->status);
    }

    public function test_wrong_secret_is_refused(): void
    {
        $this->postWebhook($this->webhookEvent($this->generation, 'processing'), secret: 'whsec_someone_else')
            ->assertStatus(400);

        $this->assertNothingStored();
    }

    public function test_tampered_body_is_refused(): void
    {
        $event = $this->webhookEvent($this->generation, 'processing');
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.json_encode($event), self::WEBHOOK_SECRET);
        config(['services.pixelforge.webhook_secret' => self::WEBHOOK_SECRET]);

        $tampered = json_encode(['type' => 'generation.succeeded'] + $event);

        $this->postSignedBody((string) $tampered, "t={$timestamp},v1={$signature}")->assertStatus(400);

        $this->assertNothingStored();
    }

    public function test_missing_header_is_refused(): void
    {
        config(['services.pixelforge.webhook_secret' => self::WEBHOOK_SECRET]);

        $this->postSignedBody((string) json_encode($this->webhookEvent($this->generation, 'processing')), null)
            ->assertStatus(400);

        $this->assertNothingStored();
    }

    public function test_malformed_header_is_refused(): void
    {
        config(['services.pixelforge.webhook_secret' => self::WEBHOOK_SECRET]);

        $this->postSignedBody('{}', 'v1=abc')->assertStatus(400);
        $this->postSignedBody('{}', 't=notanumber,v1=abc')->assertStatus(400);
        $this->postSignedBody('{}', 'garbage')->assertStatus(400);

        $this->assertNothingStored();
    }

    public function test_timestamp_more_than_five_minutes_old_is_refused(): void
    {
        $this->postWebhook($this->webhookEvent($this->generation, 'processing'), timestamp: now()->getTimestamp() - 301)
            ->assertStatus(400);

        $this->assertNothingStored();
    }

    public function test_timestamp_more_than_five_minutes_ahead_is_refused(): void
    {
        $this->postWebhook($this->webhookEvent($this->generation, 'processing'), timestamp: now()->getTimestamp() + 301)
            ->assertStatus(400);

        $this->assertNothingStored();
    }

    public function test_timestamp_just_inside_the_window_is_accepted(): void
    {
        $this->postWebhook($this->webhookEvent($this->generation, 'processing'), timestamp: now()->getTimestamp() - 299)
            ->assertOk();
    }

    public function test_any_matching_v1_signature_is_enough(): void
    {
        // Pixelforge may send more than one v1 (e.g. while a secret rotates).
        config(['services.pixelforge.webhook_secret' => self::WEBHOOK_SECRET]);
        $body = (string) json_encode($this->webhookEvent($this->generation, 'processing'));
        $timestamp = now()->getTimestamp();
        $good = hash_hmac('sha256', $timestamp.'.'.$body, self::WEBHOOK_SECRET);

        $this->postSignedBody($body, "t={$timestamp},v1=deadbeef,v1={$good}")->assertOk();
    }

    public function test_everything_is_refused_when_no_secret_is_configured(): void
    {
        $event = $this->webhookEvent($this->generation, 'processing');
        $timestamp = now()->getTimestamp();
        $body = (string) json_encode($event);
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, '');
        config(['services.pixelforge.webhook_secret' => null]);

        $this->postSignedBody($body, "t={$timestamp},v1={$signature}")->assertStatus(400);

        $this->assertNothingStored();
    }

    public function test_webhook_route_runs_outside_the_web_middleware_group(): void
    {
        // No session cookie means no web group, so no CSRF check either.
        // (Laravel skips CSRF in tests, so the cookie is the visible proof.)
        $this->postWebhook($this->webhookEvent($this->generation, 'processing'))
            ->assertOk()
            ->assertCookieMissing(config('session.cookie'));
    }
}
