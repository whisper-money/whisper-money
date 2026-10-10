<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\SavingsGoalKind;

/**
 * Rules shared by create_savings_goal and update_savings_goal.
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

        // The web forms' name rule, worded for an agent.
        $messages['name.unique'] = 'A label or goal named ":input" already exists. Pick another name.';

        foreach (self::KIND_ONLY_FIELDS[$other->value] as $field) {
            $rules[$field] = ['prohibited'];
            $messages["{$field}.prohibited"] = "{$field} only applies to {$other->value} goals; this one is {$kind->value}.";
        }

        return [$rules, $messages];
    }
}
