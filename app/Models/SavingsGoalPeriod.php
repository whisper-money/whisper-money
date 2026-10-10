<?php

namespace App\Models;

use App\Enums\MonthlyTargetType;
use Carbon\Carbon;
use Database\Factories\SavingsGoalPeriodFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One calendar month of a monthly savings goal.
 *
 * It freezes the target in force that month — editing the goal only moves the
 * month in progress — but never what was saved: that is always summed live
 * from the goal's tagged transactions, so a transaction that syncs late still
 * lands in the month it belongs to.
 *
 * @property Carbon $month
 * @property MonthlyTargetType $target_type
 * @property int|null $target_amount
 * @property float|null $target_rate
 * @property int|null $resolved_target_amount Null while a share-of-income target has no complete month of income behind it yet, and is followed live until the month closes.
 * @property Carbon|null $closed_at
 * @property Carbon|null $reminder_notified_at
 */
class SavingsGoalPeriod extends Model
{
    /** @use HasFactory<SavingsGoalPeriodFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'savings_goal_id',
        'month',
        'target_type',
        'target_amount',
        'target_rate',
        'resolved_target_amount',
        'closed_at',
        'reminder_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d',
            'target_type' => MonthlyTargetType::class,
            'target_amount' => 'integer',
            'target_rate' => 'float',
            'resolved_target_amount' => 'integer',
            'closed_at' => 'datetime',
            'reminder_notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SavingsGoal, $this> */
    public function savingsGoal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class);
    }

    public function monthKey(): string
    {
        return $this->month->format('Y-m');
    }
}
