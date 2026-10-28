<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SavingsGoalKind;
use App\Http\Requests\Api\V1\Concerns\ChecksIdsInSpace;
use App\Http\Requests\Api\V1\Concerns\ValidatesSavingsGoalWrites;
use App\Models\Account;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalWriteRules;
use App\Services\SpaceResolver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavingsGoalRequest extends FormRequest
{
    use ChecksIdsInSpace, ValidatesSavingsGoalWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return $this->savingsGoalFields(creating: true, extra: [
            /**
             * `one_off` to reach a total once, `monthly` to save towards a target every calendar month. Fixed once created.
             *
             * @example monthly
             */
            'kind' => $this->writeRules()[0]['kind'] ?? ['required', Rule::enum(SavingsGoalKind::class)],
            /**
             * Monthly goals: the id of a savings account of the goal's space. A new automation rule then tags the transfers into it to this goal, from the start of this month on. An account can feed only one running monthly goal.
             *
             * @format uuid
             */
            'auto_tag_account_id' => $this->kind() === SavingsGoalKind::Monthly
                ? ['nullable', 'bail', 'string', $this->inSpace(Account::class, 'an account'), $this->feedableAccount(...)]
                : $this->writeRules()[0]['auto_tag_account_id'] ?? ['nullable', 'string'],
        ]);
    }

    public function kind(): ?SavingsGoalKind
    {
        return SavingsGoalKind::tryFrom((string) $this->input('kind'));
    }

    protected function savingsGoalWriteRules(User $user): array
    {
        return app(SavingsGoalWriteRules::class)->forCreate($user, $this->kind());
    }

    private function feedableAccount(string $attribute, mixed $value, Closure $fail): void
    {
        $refusal = app(SavingsGoalWriteRules::class)->autoTagAccountRefusal(
            app(SpaceResolver::class)->accountOrFail(app(SpaceResolver::class)->forApiRequest($this), (string) $value),
            'Pick an account of type savings from GET /api/v1/accounts.',
            'tag contributions to the goal with its label.',
        );

        if ($refusal !== null) {
            $fail($refusal);
        }
    }
}
