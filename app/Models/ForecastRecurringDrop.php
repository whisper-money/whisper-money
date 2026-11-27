<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSpace;
use Database\Factories\ForecastRecurringDropFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring charge the user is dropping, named by its charge key (see
 * RecurringCharge::$key). It lowered `adjustment` by `reduction`, from the
 * month after it was dropped on, and `adjustment_amount` is what the drops
 * left it at, so a figure the user set by hand since is told apart;
 * `created_adjustment` says the adjustment exists for the drops alone, so
 * it goes when the last of them is undone.
 *
 * @property string $id
 * @property string $user_id
 * @property string $forecast_estimate_adjustment_id
 * @property string $charge_key
 * @property int $reduction
 * @property int $adjustment_amount
 * @property bool $created_adjustment
 * @property-read ForecastEstimateAdjustment $adjustment
 */
class ForecastRecurringDrop extends Model
{
    /** @use HasFactory<ForecastRecurringDropFactory> */
    use BelongsToSpace, HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'space_id',
        'forecast_estimate_adjustment_id',
        'charge_key',
        'reduction',
        'adjustment_amount',
        'created_adjustment',
    ];

    /** @var list<string> */
    protected $hidden = [
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'reduction' => 'integer',
            'adjustment_amount' => 'integer',
            'created_adjustment' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ForecastEstimateAdjustment, $this> */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(ForecastEstimateAdjustment::class, 'forecast_estimate_adjustment_id');
    }
}
