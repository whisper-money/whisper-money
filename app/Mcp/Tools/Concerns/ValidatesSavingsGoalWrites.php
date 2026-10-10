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
     * The fields only one kind of goal takes. Sent for the other kind they are
     * refused rather than dropped, so the agent never reports a change that
     * did not happen.
     *
     * @var array<string, list<string>>
     */
    private const KIND_ONLY_FIELDS = [
        'one_off' => ['target_amount', 'initial_amount', 'target_date'],
        'monthly' => ['monthly_target_type', 'monthly_target_amount', 'monthly_target_rate', 'notify_on_month_end_reminder', 'auto_tag_account_id'],
    ];

    /**
     * @return array{0: array<string, list<string>>, 1: array<string, string>} the rules and their messages
     */
    protected function otherKindFieldRules(string $kind): array
    {
        $other = $kind === 'monthly' ? 'one_off' : 'monthly';
        $rules = [];
        $messages = [];

        foreach (self::KIND_ONLY_FIELDS[$other] as $field) {
            $rules[$field] = ['prohibited'];
            $messages["{$field}.prohibited"] = "{$field} only applies to {$other} goals; this one is {$kind}.";
        }

        return [$rules, $messages];
    }

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
