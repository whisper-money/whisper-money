<?php

namespace App\Policies;

use App\Models\SavingsGoal;
use App\Models\User;

class SavingsGoalPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SavingsGoal $savingsGoal): bool
    {
        return $user->id === $savingsGoal->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Archiving is one-way and turns the goal read-only, so the refusal lives
     * here rather than in each controller: every caller — web, MCP, whatever
     * comes next — is covered by construction.
     */
    public function update(User $user, SavingsGoal $savingsGoal): bool
    {
        return $user->id === $savingsGoal->user_id && ! $savingsGoal->isArchived();
    }

    public function archive(User $user, SavingsGoal $savingsGoal): bool
    {
        return $this->update($user, $savingsGoal);
    }

    public function delete(User $user, SavingsGoal $savingsGoal): bool
    {
        return $user->id === $savingsGoal->user_id;
    }

    public function restore(User $user, SavingsGoal $savingsGoal): bool
    {
        return $user->id === $savingsGoal->user_id;
    }

    public function forceDelete(User $user, SavingsGoal $savingsGoal): bool
    {
        return $user->id === $savingsGoal->user_id;
    }
}
