<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSpace;
use Database\Factories\SavingsGoalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class SavingsGoal extends Model
{
    /** @use HasFactory<SavingsGoalFactory> */
    use BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'space_id',
        'label_id',
        'name',
        'target_amount',
        'target_date',
    ];

    /** @var list<string> */
    protected $hidden = [
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'integer',
            'target_date' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Label, $this> */
    public function label(): BelongsTo
    {
        return $this->belongsTo(Label::class);
    }

    /**
     * Money set aside toward the goal, in cents. Contributions are outflows, so
     * the natural transaction sign is negated (same convention as budgets): a
     * transfer out (−) adds, a withdrawal back (+) subtracts.
     */
    public function savedAmountInCents(): int
    {
        if ($this->label === null) {
            return 0;
        }

        return -1 * (int) $this->label->transactions()->sum('transactions.amount');
    }

    /**
     * Linear progress + projection, computed from primitives so it stays a pure,
     * testable function. Dates are day-granular. `rate_per_day` is cents/day and
     * feeds the chart's dotted projection line client-side.
     *
     * @return array{
     *     saved: int,
     *     target: int,
     *     percentage: float,
     *     target_date: ?string,
     *     rate_per_day: float,
     *     expected_today: ?int,
     *     status: ?string,
     *     estimated_date: ?string,
     *     required_per_month: ?int,
     * }
     */
    public static function project(int $saved, int $target, Carbon $start, ?Carbon $targetDate, Carbon $today): array
    {
        $start = $start->copy()->startOfDay();
        $today = $today->copy()->startOfDay();

        $daysElapsed = max(1, $start->diffInDays($today));
        $ratePerDay = $saved / $daysElapsed;
        $remaining = $target - $saved;

        $percentage = $target > 0 ? round(($saved / $target) * 100, 1) : 0.0;

        $estimatedDate = null;
        if ($saved >= $target) {
            $estimatedDate = $today->toDateString();
        } elseif ($ratePerDay > 0) {
            $estimatedDate = $today->copy()->addDays((int) ceil($remaining / $ratePerDay))->toDateString();
        }

        $expectedToday = null;
        $status = null;
        $requiredPerMonth = null;

        if ($targetDate !== null) {
            $targetDate = $targetDate->copy()->startOfDay();
            $totalDays = max(1, $start->diffInDays($targetDate));
            $expectedToday = (int) round($target * min($daysElapsed, $totalDays) / $totalDays);

            $tolerance = $target * 0.02;
            if ($saved >= $target) {
                $status = 'completed';
            } elseif ($saved < $expectedToday - $tolerance) {
                $status = 'behind';
            } elseif ($saved > $expectedToday + $tolerance) {
                $status = 'ahead';
            } else {
                $status = 'on_track';
            }

            $daysLeft = $today->diffInDays($targetDate, false);
            if ($remaining > 0 && $daysLeft > 0) {
                $requiredPerMonth = (int) round(($remaining / $daysLeft) * 30);
            } elseif ($remaining <= 0) {
                $requiredPerMonth = 0;
            }
        }

        return [
            'saved' => $saved,
            'target' => $target,
            'percentage' => $percentage,
            'target_date' => $targetDate?->toDateString(),
            'rate_per_day' => round($ratePerDay, 2),
            'expected_today' => $expectedToday,
            'status' => $status,
            'estimated_date' => $estimatedDate,
            'required_per_month' => $requiredPerMonth,
        ];
    }
}
