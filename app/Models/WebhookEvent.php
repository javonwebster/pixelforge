<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One verified Pixelforge webhook delivery, stored once per event id.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $pixelforge_generation_id
 * @property int|null $delivery_attempt
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
class WebhookEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivery_attempt' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * The generation object, for generation.* events.
     *
     * @return array<string, mixed>|null
     */
    public function generationObject(): ?array
    {
        $object = data_get($this->payload, 'data.object');

        return str_starts_with($this->type, 'generation.') && is_array($object) ? $object : null;
    }
}
