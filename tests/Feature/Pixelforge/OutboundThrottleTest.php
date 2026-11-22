<?php

namespace Tests\Feature\Pixelforge;

use App\Services\Pixelforge\OutboundThrottle;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundThrottleTest extends TestCase
{
    public function test_allows_55_requests_in_any_60_seconds(): void
    {
        $throttle = app(OutboundThrottle::class);
        $this->freezeTime();

        for ($i = 0; $i < 55; $i++) {
            $this->assertSame(0, $throttle->reserve());
        }

        $this->assertSame(60, $throttle->reserve());
    }

    public function test_a_slot_frees_up_when_the_oldest_request_leaves_the_window(): void
    {
        $throttle = app(OutboundThrottle::class);
        $this->freezeTime();

        $throttle->reserve();                  // t = 0
        $this->travel(30)->seconds();
        for ($i = 0; $i < 54; $i++) {
            $throttle->reserve();              // t = 30
        }

        $this->assertSame(30, $throttle->reserve());

        $this->travel(31)->seconds();          // t = 61: the first has left
        $this->assertSame(0, $throttle->reserve());
        $this->assertGreaterThan(0, $throttle->reserve());
    }

    public function test_pauses_for_a_minute_when_pixelforge_says_none_remain(): void
    {
        $throttle = app(OutboundThrottle::class);
        $this->freezeTime();
        Http::fake(['*' => Http::response([], 200, ['X-RateLimit-Remaining' => '0'])]);

        $throttle->observe(Http::get('https://pixelforge.test/v1/generations/x'));

        $this->assertSame(60, $throttle->reserve());
        $this->travel(61)->seconds();
        $this->assertSame(0, $throttle->reserve());
    }

    public function test_ignores_a_response_with_requests_remaining(): void
    {
        $throttle = app(OutboundThrottle::class);
        $response = new Response(new \GuzzleHttp\Psr7\Response(200, ['X-RateLimit-Remaining' => '12']));

        $throttle->observe($response);

        $this->assertSame(0, $throttle->reserve());
    }
}
