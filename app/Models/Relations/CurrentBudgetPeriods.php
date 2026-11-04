<?php

namespace App\Models\Relations;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A budget's periods covering its owner's today, latest start first.
 *
 * "Today" depends on whose budget it is (see User::localToday()), and a plain
 * relation cannot know that when eager loaded: Laravel builds the relation on a
 * blank Budget, with no owner, and only hands over the real budgets afterwards.
 * So the date is applied per budget in addEagerConstraints(), where they are
 * known, grouped by the date it is for each owner. A lazily read relation takes
 * it from its own budget.
 *
 * @template TDeclaringModel of Budget
 *
 * @extends HasMany<BudgetPeriod, TDeclaringModel>
 */
class CurrentBudgetPeriods extends HasMany
{
    public function addConstraints(): void
    {
        parent::addConstraints();

        // Outside the check: an eager load runs with constraints off and needs
        // the order just the same.
        $this->getRelationQuery()->orderByDesc('start_date');

        if (static::$constraints) {
            $this->getRelationQuery()->covering($this->parent->ownerToday());
        }
    }

    /**
     * @param  array<int, Budget>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        parent::addEagerConstraints($models);

        $budgetIdsByDay = $this->budgetIdsByOwnerToday($models);

        $this->getRelationQuery()->where(function (Builder $query) use ($budgetIdsByDay): void {
            foreach ($budgetIdsByDay as $day => $budgetIds) {
                $query->orWhere(fn (Builder $periods) => $periods
                    ->whereIn($this->foreignKey, $budgetIds)
                    ->covering(Carbon::parse($day)));
            }
        });
    }

    /**
     * `whereHas('currentPeriods')`, `withCount` and friends build the relation
     * on a blank budget and never call addEagerConstraints(), so they would
     * match every period, not today's. Refused rather than silently wrong:
     * filter `periods` with `covering()` and the owner's today instead.
     *
     * @param  Builder<BudgetPeriod>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  mixed  $columns
     * @return Builder<BudgetPeriod>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        throw new LogicException('currentPeriods depends on each owner\'s today and cannot be used in an existence query; constrain `periods` with covering() instead.');
    }

    /**
     * The budgets' ids grouped by their owner's today, with one query for the
     * owners that are not loaded already.
     *
     * @param  array<int, Budget>  $budgets
     * @return array<string, list<string>>
     */
    private function budgetIdsByOwnerToday(array $budgets): array
    {
        $missingOwnerIds = collect($budgets)
            ->reject(fn (Budget $budget): bool => $budget->relationLoaded('user'))
            ->pluck('user_id')
            ->filter()
            ->unique();

        $owners = $missingOwnerIds->isEmpty()
            ? collect()
            : User::query()->whereKey($missingOwnerIds->all())->get(['id', 'timezone'])->keyBy('id');

        $budgetIdsByDay = [];

        foreach ($budgets as $budget) {
            $owner = $budget->relationLoaded('user') ? $budget->user : $owners->get($budget->user_id);
            $day = ($owner?->localToday() ?? today())->toDateString();
            $budgetIdsByDay[$day][] = $budget->getKey();
        }

        return $budgetIdsByDay;
    }
}
