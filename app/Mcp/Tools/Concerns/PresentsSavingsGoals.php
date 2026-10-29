<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalPresenter;

/**
 * The savings goal shape every savings goal tool returns, so list, create and
 * update hand the agent the same row. SavingsGoalPresenter shapes it, for
 * `/api/v1/savings-goals` too.
 */
trait PresentsSavingsGoals
{
    /**
     * Every goal of $user in the space, one-off ones first, read in one query.
     *
     * @return list<array<string, mixed>>
     */
    protected function presentSavingsGoals(User $user, Space $space): array
    {
        return app(SavingsGoalPresenter::class)->all($user, $space);
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentSavingsGoal(SavingsGoal $goal): array
    {
        return app(SavingsGoalPresenter::class)->one($goal);
    }
}
