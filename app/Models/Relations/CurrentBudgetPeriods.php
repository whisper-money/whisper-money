<?php

namespace App\Models\Relations;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
                    ->whereIn('budget_id', $budgetIds)
                    ->covering(Carbon::parse($day)));
            }
        });
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
