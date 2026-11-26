<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\ForecastItemOverrideFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "this month only" exception to the occurrence a forecast line's schedule
 * puts on `occurrence_date`: another amount, another date, or skipped.
 *
 * @property string $id
 * @property string $forecast_item_id
 * @property Carbon $occurrence_date
 * @property int|null $amount
 * @property Carbon|null $moved_to
 * @property bool $skipped
 */
class ForecastItemOverride extends Model
{
    /** @use HasFactory<ForecastItemOverrideFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'forecast_item_id',
        'occurrence_date',
        'amount',
        'moved_to',
        'skipped',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'skipped' => false,
    ];

    protected function casts(): array
    {
        return [
            'occurrence_date' => 'date:Y-m-d',
            'amount' => 'integer',
            'moved_to' => 'date:Y-m-d',
            'skipped' => 'boolean',
        ];
    }

    /** @return BelongsTo<ForecastItem, $this> */
    public function forecastItem(): BelongsTo
    {
        return $this->belongsTo(ForecastItem::class);
    }
}
