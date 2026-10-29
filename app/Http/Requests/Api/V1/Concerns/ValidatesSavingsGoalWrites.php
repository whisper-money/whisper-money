<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Enums\MonthlyTargetType;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * What the savings goal create and update requests share: the
 * SavingsGoalWriteRules rules and messages the MCP tools validate with too,
 * each field documented once. The fields of the other kind of goal are
 * refused with a message naming the kind they belong to.
 */
trait ValidatesSavingsGoalWrites
{
    use RefusesUnknownFields;

    /**
     * @var array{0: array<string, array<mixed>>, 1: array<string, string>}|null
     */
    private ?array $writeRules = null;

    /**
     * The SavingsGoalWriteRules rules and messages for this write.
     *
     * @return array{0: array<string, array<mixed>>, 1: array<string, string>}
     */
    abstract protected function savingsGoalWriteRules(User $user): array;

    /**
     * @param  bool  $creating  a new goal needs a name
     * @param  array<string, array<mixed>>  $extra  the fields only this write takes
     * @return array<string, array<mixed>>
     */
    protected function savingsGoalFields(bool $creating, array $extra = []): array
    {
        $rules = $this->writeRules()[0];

        return [
            /**
             * The goal's name. It also names the label its contributions are tagged with, so it must not clash with one of your labels.
             *
             * @example Emergency fund
             */
            'name' => $rules['name'] ?? [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            ...$extra,
            /**
             * One-off goals: the total to reach, in the minor units of your account's currency.
             *
             * @example 1000000
             */
            'target_amount' => $rules['target_amount'] ?? ['integer'],
            /**
             * One-off goals: what is already saved towards it, in minor units. Defaults to `0`.
             *
             * @example 50000
             */
            'initial_amount' => $rules['initial_amount'] ?? ['integer'],
            /**
             * One-off goals: the day to reach the total by, `YYYY-MM-DD`; in the future for a new goal. `null` clears it.
             *
             * @example 2027-06-30
             */
            'target_date' => $rules['target_date'] ?? ['nullable', 'date_format:Y-m-d'],
            /**
             * Monthly goals: `amount` for a fixed sum each month, `income_rate` for a share of the average income of the three previous complete months. Required on a new monthly goal, and on an edit alongside `monthly_target_amount` or `monthly_target_rate`; switching type clears the other value.
             *
             * @example amount
             */
            'monthly_target_type' => $rules['monthly_target_type'] ?? [Rule::enum(MonthlyTargetType::class)],
            /**
             * Monthly goals with an `amount` target: the sum to save each month, in minor units.
             *
             * @example 30000
             */
            'monthly_target_amount' => $rules['monthly_target_amount'] ?? ['nullable', 'integer'],
            /**
             * Monthly goals with an `income_rate` target: the percentage of income to save each month, above 0 and up to 100, two decimals at most.
             *
             * @example 15
             */
            'monthly_target_rate' => $rules['monthly_target_rate'] ?? ['nullable', 'numeric'],
            /**
             * Monthly goals: email a reminder 5 days before the month ends while the month is short of its target. A new goal defaults to your notification setting.
             *
             * @example true
             */
            'notify_on_month_end_reminder' => $rules['notify_on_month_end_reminder'] ?? ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->writeRules()[1];
    }

    /**
     * Without a user there are no rules to share: that is the API spec
     * generator reading rules() off a bare request, and each field falls back
     * to its type alone.
     *
     * @return array{0: array<string, array<mixed>>, 1: array<string, string>}
     */
    private function writeRules(): array
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return [[], []];
        }

        return $this->writeRules ??= $this->savingsGoalWriteRules($user);
    }
}
