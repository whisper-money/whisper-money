<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSpace;
use Carbon\Carbon;
use Database\Factories\ForecastEstimateAdjustmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The user's own figure for the estimated rest of a parent category on one
 * account, from `starts_on` until `ends_on` (open-ended when null). It only
 * changes the forecast, never the budget.
 *
 * @property string $id
 * @property string $user_id
 * @property string $account_id
 * @property string|null $category_id
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property int $amount
 */
class ForecastEstimateAdjustment extends Model
{
    /** @use HasFactory<ForecastEstimateAdjustmentFactory> */
    use BelongsToSpace, HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'space_id',
        'account_id',
        'category_id',
        'starts_on',
        'ends_on',
        'amount',
    ];

    /** @var list<string> */
    protected $hidden = [
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
