<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSpace;
use Carbon\Carbon;
use Database\Factories\ForecastSnapshotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The forecast of a calendar month, frozen when the month opens, that the
 * month's actual figures are compared against.
 *
 * @property string $id
 * @property string $user_id
 * @property Carbon $month
 * @property array<string, mixed> $payload
 * @property Carbon $frozen_at
 */
class ForecastSnapshot extends Model
{
    /** @use HasFactory<ForecastSnapshotFactory> */
    use BelongsToSpace, HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'space_id',
        'month',
        'payload',
        'frozen_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d',
            'payload' => 'array',
            'frozen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
