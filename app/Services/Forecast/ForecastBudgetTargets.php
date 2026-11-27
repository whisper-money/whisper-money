<?php

namespace App\Services\Forecast;

use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The monthly budgets the forecast can stand on: a budget counts for a parent
 * category only when every category it tracks hangs from that parent and it
 * tracks no label, so its limit is about that parent and nothing else. One
 * that mixes parents or follows labels is left out, and the parent falls back
 * to its median. Catch-all and archived budgets never count; several budgets
 * that fit the same parent add up. Amounts are the latest period's limit, in
 * the user's currency.
 */
class ForecastBudgetTargets
{
    /**
     * @param  array<string, string>  $parents  Each category's top-level ancestor.
     * @return array<string, int> Monthly limit by parent category id.
     */
    public function monthlyByParent(User $user, string $spaceId, array $parents): array
    {
        $targets = [];

        $budgets = $this->budgets($user, $spaceId)
            ->where('period_type', BudgetPeriodType::Monthly)
            ->whereDoesntHave('labels')
            ->with(['periods' => fn ($query) => $query->orderByDesc('start_date')])
            ->get();

        foreach ($budgets as $budget) {
            $parent = $this->soleParent($budget, $parents);
            $limit = $budget->periods->first()?->allocated_amount;

            if ($parent !== null && $limit !== null) {
                $targets[$parent] = ($targets[$parent] ?? 0) + (int) $limit;
            }
        }

        return $targets;
    }

    /**
     * The names of the budgets the forecast cannot stand on, because they
     * are not monthly, follow labels or mix parent categories: the review
     * says so, since their categories quietly fall back to the median.
     *
     * @param  array<string, string>  $parents  Each category's top-level ancestor.
     * @return list<string>
     */
    public function unusableNames(User $user, string $spaceId, array $parents): array
    {
        return $this->budgets($user, $spaceId)
            ->withExists('labels')
            ->get()
            ->filter(fn (Budget $budget): bool => $this->usableParent($budget, $parents) === null)
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * The live budgets that are not catch-alls, each with its categories.
     *
     * @return Builder<Budget>
     */
    private function budgets(User $user, string $spaceId): Builder
    {
        return Budget::query()
            ->where('user_id', $user->id)
            ->forSpace($spaceId)
            ->notArchived()
            ->where('is_catch_all', false)
            ->with('categories:id');
    }

    /**
     * The parent a monthly budget without labels is about, or null when it is
     * not one the forecast can use.
     *
     * @param  array<string, string>  $parents
     */
    private function usableParent(Budget $budget, array $parents): ?string
    {
        if ($budget->period_type !== BudgetPeriodType::Monthly || (bool) $budget->getAttribute('labels_exists')) {
            return null;
        }

        return $this->soleParent($budget, $parents);
    }

    /**
     * @param  array<string, string>  $parents
     */
    private function soleParent(Budget $budget, array $parents): ?string
    {
        $roots = $budget->categories
            ->map(fn (Category $category): ?string => $parents[$category->id] ?? null)
            ->unique();

        return $roots->count() === 1 ? $roots->first() : null;
    }
}
