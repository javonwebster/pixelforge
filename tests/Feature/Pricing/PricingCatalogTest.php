<?php

namespace Tests\Feature\Pricing;

use App\Services\Pricing\PricingCatalog;
use App\Services\Pricing\Rates;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PricingCatalogTest extends TestCase
{
    /**
     * Shape from the docs' GET /v1/pricing response.
     *
     * @param  array<string, string>  $rates
     * @return array<string, mixed>
     */
    private function pricingResponse(array $rates = []): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => $rates + [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
            ],
        ];
    }

    public function test_reads_current_rates_from_pixelforge(): void
    {
        Http::fake(['*/v1/pricing' => Http::response($this->pricingResponse(['output_tokens' => '45.00']))]);

        $rates = app(PricingCatalog::class)->currentRates();

        $this->assertSame('45.00', $rates->output->toString());
        Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.promptedit.dev/v1/pricing'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_caches_rates_for_an_hour(): void
    {
        Http::fake(['*/v1/pricing' => Http::response($this->pricingResponse())]);
        $catalog = app(PricingCatalog::class);

        $catalog->currentRates();
        $catalog->currentRates();
        $this->travel(59)->minutes();
        $catalog->currentRates();

        Http::assertSentCount(1);

        $this->travel(2)->minutes();
        $catalog->currentRates();

        Http::assertSentCount(2);
    }

    public function test_falls_back_to_documented_rates_when_pixelforge_is_down(): void
    {
        Http::fake(['*/v1/pricing' => Http::response(['error' => ['code' => 'overloaded']], 503)]);

        $this->assertEquals(Rates::documented(), app(PricingCatalog::class)->currentRates());
    }

    public function test_retries_a_minute_after_falling_back(): void
    {
        Http::fakeSequence('*/v1/pricing')
            ->pushStatus(503)
            ->push($this->pricingResponse(['output_tokens' => '45.00']));
        $catalog = app(PricingCatalog::class);

        $this->assertEquals(Rates::documented(), $catalog->currentRates());
        $this->assertEquals(Rates::documented(), $catalog->currentRates());   // still within the minute

        $this->travel(61)->seconds();

        $this->assertSame('45.00', $catalog->currentRates()->output->toString());
        Http::assertSentCount(2);
    }

    public function test_refuses_rates_in_an_unexpected_unit(): void
    {
        Http::fake(['*/v1/pricing' => Http::response(['unit' => 'per_1k_tokens'] + $this->pricingResponse(['output_tokens' => '0.04']))]);

        $this->assertEquals(Rates::documented(), app(PricingCatalog::class)->currentRates());
    }

    public function test_refuses_float_rates(): void
    {
        $body = $this->pricingResponse();
        $body['rates']['output_tokens'] = 40.1;
        Http::fake(['*/v1/pricing' => Http::response($body)]);

        $this->assertEquals(Rates::documented(), app(PricingCatalog::class)->currentRates());
    }
}
