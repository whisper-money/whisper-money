<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesRouteSavingsGoal;
use App\Http\Requests\Api\V1\Concerns\ValidatesSavingsGoalWrites;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalWriteRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSavingsGoalRequest extends FormRequest
{
    use ResolvesRouteSavingsGoal, ValidatesSavingsGoalWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return $this->savingsGoalFields(creating: false);
    }

    /**
     * Refuse unknown fields, and the kind with how to switch it.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, [
            ...$this->unknownFieldRefusals(),
            ...($this->has('kind') ? ['kind' => $this->messages()['kind.prohibited']] : []),
        ])];
    }

    protected function savingsGoalWriteRules(User $user): array
    {
        return app(SavingsGoalWriteRules::class)->forUpdate($user, $this->savingsGoal());
    }
}
