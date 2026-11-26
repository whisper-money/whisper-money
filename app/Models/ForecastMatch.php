<?php

namespace App\Models;

use App\Enums\ForecastMatchedBy;
use Carbon\Carbon;
use Database\Factories\ForecastMatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A transaction that paid one occurrence of a forecast line. `deviation` is
 * the actual amount minus the expected one, in minor units.
 *
 * @property string $id
 * @property string $forecast_item_id
 * @property Carbon $occurrence_date
 * @property string $transaction_id
 * @property ForecastMatchedBy $matched_by
 * @property int $deviation
 */
class ForecastMatch extends Model
{
    /** @use HasFactory<ForecastMatchFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'forecast_item_id',
        'occurrence_date',
        'transaction_id',
        'matched_by',
        'deviation',
    ];

    protected function casts(): array
    {
        return [
            'occurrence_date' => 'date:Y-m-d',
            'matched_by' => ForecastMatchedBy::class,
            'deviation' => 'integer',
        ];
    }

    /** @return BelongsTo<ForecastItem, $this> */
    public function forecastItem(): BelongsTo
    {
        return $this->belongsTo(ForecastItem::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
