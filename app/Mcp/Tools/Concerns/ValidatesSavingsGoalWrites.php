<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Label;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Checks shared by create_savings_goal and update_savings_goal.
 */
trait ValidatesSavingsGoalWrites
{
    /**
     * A goal's name is also its label's, and label names are unique per user.
     *
     * @param  string|null  $exceptLabelId  the goal's own label, when renaming it
     */
    protected function assertSavingsGoalNameIsFree(User $user, string $name, ?string $exceptLabelId = null): void
    {
        $taken = Label::query()
            ->where('user_id', $user->id)
            ->where('name', $name)
            ->when($exceptLabelId, fn ($query, string $labelId) => $query->whereKeyNot($labelId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "A label or goal named \"{$name}\" already exists. Pick another name.",
            ]);
        }
    }
}
