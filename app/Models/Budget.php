<?php

namespace App\Models;

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToSpace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property RolloverType $rollover_type
 * @property BudgetPeriodType $period_type
 * @property Carbon|null $archived_at
 */
class Budget extends Model
{
    use Archivable, BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'space_id',
        'name',
        'position',
        'period_type',
        'period_start_day',
        'rollover_type',
        'is_catch_all',
        'archived_at',
        'notify_on_new_transaction',
        'notify_on_close_to_limit',
        'notify_on_over_limit',
    ];

    /** @var list<string> */
    protected $hidden = [
        'period_duration',
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'period_type' => BudgetPeriodType::class,
            'rollover_type' => RolloverType::class,
            'position' => 'integer',
            'period_start_day' => 'integer',
            'is_catch_all' => 'boolean',
            'notify_on_new_transaction' => 'boolean',
            'notify_on_close_to_limit' => 'boolean',
            'notify_on_over_limit' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /** @return BelongsToMany<Label, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class);
    }

    /** @return HasMany<BudgetPeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(BudgetPeriod::class);
    }

    /**
     * The period covering today, if there is one.
     *
     * Ordered because more than one can cover today. Overlapping periods are
     * not hypothetical - every biweekly budget created before the fix to
     * `generatePreviousPeriod` has a pair, and a monthly budget anchored past
     * the 28th can still produce one - and an unordered `first()` resolves to
     * whatever MySQL returns first, which on the `(budget_id, start_date)`
     * index is the *earliest* starting window. That is the wrong one: it is the
     * period the user did not configure, and `BudgetController::show` cannot
     * navigate out of it because its previous/next queries are strict
     * comparisons that step over an overlapping sibling. Taking the latest
     * start picks the period the chain actually continues from.
     */
    public function getCurrentPeriod(): ?BudgetPeriod
    {
        return $this->periods()
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->orderByDesc('start_date')
            ->first();
    }

    /**
     * The next period along from a given one, if this budget has one.
     *
     * Lives here rather than on the service so that the query is scoped to the
     * budget by construction - stamping one budget's leftover onto another
     * budget's period is not a mistake worth leaving reachable.
     *
     * Inclusive of a period starting on the given one's end date, because not
     * every chain is a clean tiling: 25 live budgets have periods that touch or
     * overlap, most of them biweekly, since `calculatePeriodDates` snaps to a
     * weekday rather than to a 14-day grid. Excluding that day skipped the real
     * successor and appended yet another period alongside it.
     */
    public function periodFollowing(BudgetPeriod $period): ?BudgetPeriod
    {
        return $this->periods()
            ->whereKeyNot($period->getKey())
            ->where('start_date', '>=', $period->end_date)
            ->orderBy('start_date')
            ->first();
    }
}
