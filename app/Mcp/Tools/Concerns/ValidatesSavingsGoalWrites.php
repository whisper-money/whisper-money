<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\SavingsGoalKind;
use App\Models\Label;
use App\Models\Space;
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
        SavingsGoalKind::OneOff->value => ['target_amount', 'initial_amount', 'target_date'],
        SavingsGoalKind::Monthly->value => ['monthly_target_type', 'monthly_target_amount', 'monthly_target_rate', 'notify_on_month_end_reminder', 'auto_tag_account_id'],
    ];

    /**
     * @return array{0: array<string, list<string>>, 1: array<string, string>} the rules and their messages
     */
    protected function otherKindFieldRules(SavingsGoalKind $kind): array
    {
        $other = $kind === SavingsGoalKind::Monthly ? SavingsGoalKind::OneOff : SavingsGoalKind::Monthly;
        $rules = [];
        $messages = [];

        foreach (self::KIND_ONLY_FIELDS[$other->value] as $field) {
            $rules[$field] = ['prohibited'];
            $messages["{$field}.prohibited"] = "{$field} only applies to {$other->value} goals; this one is {$kind->value}.";
        }

        return [$rules, $messages];
    }

    /**
     * A goal's name is also its label's, and label names are unique per space,
     * like create_label checks them.
     *
     * @param  string|null  $exceptLabelId  the goal's own label, when renaming it
     */
    protected function assertSavingsGoalNameIsFree(Space $space, string $name, ?string $exceptLabelId = null): void
    {
        $taken = Label::query()
            ->forSpace($space)
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
