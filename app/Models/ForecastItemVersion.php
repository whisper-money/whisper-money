<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\ForecastItemVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The signed amount of a forecast line from `effective_from` on, in minor
 * units: negative for money leaving the line's account.
 *
 * @property string $id
 * @property string $forecast_item_id
 * @property Carbon $effective_from
 * @property int $amount
 */
class ForecastItemVersion extends Model
{
    /** @use HasFactory<ForecastItemVersionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'forecast_item_id',
        'effective_from',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<ForecastItem, $this> */
    public function forecastItem(): BelongsTo
    {
        return $this->belongsTo(ForecastItem::class);
    }
}
