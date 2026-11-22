<?php

namespace App\Models;

use App\Enums\LedgerType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed movement of a user's credit, in nanodollars. Append-only:
 * written only by LedgerService, never updated or deleted.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $generation_id
 * @property LedgerType $type
 * @property int $amount_nanos
 * @property CarbonImmutable $created_at
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => LedgerType::class,
            'amount_nanos' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
