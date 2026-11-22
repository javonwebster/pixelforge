<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw rows for tests. Holds aren't written here: tests that need the
 * ledger go through CreateGeneration or LedgerService.
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Medium, 1024x1024, 200 characters: the spec's worked example.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => str_repeat('a', 200),
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'reference_images' => [],
            'estimated_price_nanos' => 72_500_000,
            'max_price_nanos' => 86_612_500,
        ];
    }

    public function status(GenerationStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
