<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property Carbon $start_date
 * @property Carbon $end_date
 */
class BudgetPeriod extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'budget_id',
        'start_date',
        'end_date',
        'allocated_amount',
        'carried_over_amount',
        'processing_historical',
        'close_to_limit_notified',
        'over_limit_notified',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'allocated_amount' => 'integer',
            'carried_over_amount' => 'integer',
            'processing_historical' => 'boolean',
            'close_to_limit_notified' => 'boolean',
            'over_limit_notified' => 'boolean',
        ];
    }

    /** @return BelongsTo<Budget, $this> */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Total spent in this period, in cents. BudgetTransaction amounts are
     * stored positive for expenses, so summing them gives the amount spent.
     */
    public function spentAmount(): int
    {
        return (int) $this->budgetTransactions()->sum('amount');
    }

    /**
     * What is left of this period's limit, in cents. Deliberately ignores
     * `carried_over_amount`: the budget cards, the spending chart and the
     * limit alerts all judge a period against its allocated amount alone, so a
     * second definition of "remaining" would contradict them.
     */
    public function remainingAmount(): int
    {
        return $this->allocated_amount - $this->spentAmount();
    }

    /** @return HasMany<BudgetTransaction, $this> */
    public function budgetTransactions(): HasMany
    {
        return $this->hasMany(BudgetTransaction::class);
    }
}
