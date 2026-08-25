<?php

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;

class BudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Budget $budget): bool
    {
        return $user->id === $budget->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Archiving is one-way and turns the budget read-only, so the refusal lives
     * here rather than in each controller: every caller — web, MCP, whatever
     * comes next — is covered by construction.
     */
    public function update(User $user, Budget $budget): bool
    {
        return $user->id === $budget->user_id && ! $budget->isArchived();
    }

    public function archive(User $user, Budget $budget): bool
    {
        return $this->update($user, $budget);
    }

    public function delete(User $user, Budget $budget): bool
    {
        return $user->id === $budget->user_id;
    }

    public function restore(User $user, Budget $budget): bool
    {
        return $user->id === $budget->user_id;
    }

    public function forceDelete(User $user, Budget $budget): bool
    {
        return $user->id === $budget->user_id;
    }
}
