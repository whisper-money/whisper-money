<?php

namespace App\Services\SavingsGoals;

use App\Enums\AccountType;
use App\Enums\SavingsGoalKind;
use App\Http\Requests\Concerns\ValidatesMonthlySavingsTarget;
use App\Http\Requests\Concerns\ValidatesOneOffSavingsTarget;
use App\Http\Requests\Concerns\ValidatesSavingsGoalName;
use App\Models\Account;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Support\Money;
use Illuminate\Validation\Rule;

/**
 * The rules and messages a savings goal is created and edited with, shared by
 * the savings goal MCP tools and `/api/v1/savings-goals`. Each caller adds
 * `auto_tag_account_id` its own way and names the valid ids in its own words;
 * what it may point at is autoTagAccountRefusal()'s to say.
 */
class SavingsGoalWriteRules
{
    use ValidatesMonthlySavingsTarget, ValidatesOneOffSavingsTarget, ValidatesSavingsGoalName;

    /**
     * The fields only one kind of goal takes. Sent for the other kind they are
     * refused rather than dropped, so the caller never reports a change that
     * did not happen.
     *
     * @var array<string, list<string>>
     */
    private const KIND_ONLY_FIELDS = [
        SavingsGoalKind::OneOff->value => ['target_amount', 'initial_amount', 'target_date'],
        SavingsGoalKind::Monthly->value => ['monthly_target_type', 'monthly_target_amount', 'monthly_target_rate', 'notify_on_month_end_reminder', 'auto_tag_account_id'],
    ];

    /**
     * The rules for a new goal of $kind, or for the name and kind alone while
     * the kind sent is not a valid one.
     *
     * @return array{0: array<string, array<mixed>>, 1: array<string, string>} the rules and their messages
     */
    public function forCreate(User $user, ?SavingsGoalKind $kind): array
    {
        [$otherKindRules, $messages] = $kind === null ? [[], []] : $this->otherKindFieldRules($kind);

        $rules = [
            'name' => $this->savingsGoalNameRules($user->id, creating: true),
            'kind' => ['required', Rule::enum(SavingsGoalKind::class)],
            ...$otherKindRules,
            ...match ($kind) {
                SavingsGoalKind::Monthly => $this->monthlyTargetRules(creating: true),
                default => $this->oneOffTargetRules(creating: true),
            },
        ];

        return [$rules, [...$messages, ...$this->amountLimitMessages($user)]];
    }

    /**
     * The rules for an edit of $goal: only what is sent changes, and its kind
     * is fixed.
     *
     * @return array{0: array<string, array<mixed>>, 1: array<string, string>} the rules and their messages
     */
    public function forUpdate(User $user, SavingsGoal $goal): array
    {
        [$otherKindRules, $messages] = $this->otherKindFieldRules($goal->kind);

        $rules = [
            'name' => $this->savingsGoalNameRules($user->id, creating: false, ownLabelId: $goal->label_id),
            // Refused rather than ignored, like the other fields this goal
            // does not take: the caller must not report a switch that did not
            // happen.
            'kind' => ['prohibited'],
            ...$otherKindRules,
            ...($goal->isMonthly() ? $this->monthlyTargetRules(creating: false) : $this->oneOffTargetRules(creating: false)),
        ];

        return [$rules, [
            ...$messages,
            ...$this->amountLimitMessages($user),
            'kind.prohibited' => 'A goal\'s kind is fixed once created. To switch, create a new goal of the other kind.',
        ]];
    }

    /**
     * Why $account cannot feed a new monthly goal's auto-tag rule, or null
     * when it can. Only a savings account: on any other type an incoming
     * transfer would count against the goal rather than towards it.
     *
     * @param  string  $pickHint  appended when the account is not a savings one, telling the caller where to find one
     * @param  string  $linkHint  appended when another goal already uses it, telling the caller how to link contributions by hand
     */
    public function autoTagAccountRefusal(Account $account, string $pickHint, string $linkHint): ?string
    {
        if ($account->type !== AccountType::Savings) {
            return "Account {$account->id} is not a savings account. {$pickHint}";
        }

        // Rules stop at the first match: a second goal on the same account
        // would never see a transfer.
        $owner = SavingsGoal::autoTaggingAccount($account->id);

        if ($owner !== null) {
            return "Account {$account->id} already feeds the running monthly goal \"{$owner->name}\" ({$owner->id}). Pick another savings account, or leave auto_tag_account_id out and {$linkHint}";
        }

        return null;
    }

    /**
     * @return array{0: array<string, list<string>>, 1: array<string, string>} the rules and their messages
     */
    private function otherKindFieldRules(SavingsGoalKind $kind): array
    {
        $other = $kind === SavingsGoalKind::Monthly ? SavingsGoalKind::OneOff : SavingsGoalKind::Monthly;
        $rules = [];
        $messages = [];

        // The web forms' name rule, worded for a program.
        $messages['name.unique'] = 'A label or goal named ":input" already exists. Pick another name.';

        foreach (self::KIND_ONLY_FIELDS[$other->value] as $field) {
            $rules[$field] = ['prohibited'];
            $messages["{$field}.prohibited"] = "{$field} only applies to {$other->value} goals; this one is {$kind->value}.";
        }

        return [$rules, $messages];
    }

    /**
     * The amount bounds with the major-unit figure next to the minor units the
     * field takes, so the caller can tell the user the limit in their money.
     *
     * @return array<string, string>
     */
    private function amountLimitMessages(User $user): array
    {
        $currency = $user->currency_code ?? 'USD';
        $messages = [];

        // Each field with its own floor: a target needs one minor unit, what
        // is already saved may be nothing.
        foreach (['target_amount' => 1, 'monthly_target_amount' => 1, 'initial_amount' => 0] as $field => $min) {
            $messages["{$field}.min"] = "{$field} is in minor units of {$currency}: at least :min, which is ".Money::format($min, $currency).'.';
            $messages["{$field}.max"] = "{$field} is in minor units of {$currency}: at most :max, which is ".Money::format(SavingsGoal::MAX_AMOUNT, $currency).'.';
        }

        return $messages;
    }
}
