<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Budget;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * For the `/api/v1/budgets/{budget}` write requests: budgets cover the user's
 * whole account rather than a space, so the budget is looked up among the
 * user's own, and someone else's is a 404 before anything is validated. An
 * archived budget is read-only for good (BudgetPolicy), but it can still be
 * deleted.
 */
trait ResolvesRouteBudget
{
    private ?Budget $routeBudget = null;

    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();

        return $user->can($this->isMethod('DELETE') ? 'delete' : 'update', $this->budget());
    }

    public function budget(): Budget
    {
        /** @var User $user */
        $user = $this->user();

        return $this->routeBudget ??= Budget::ownedByOrFail($user, (string) $this->route('budget'));
    }

    protected function failedAuthorization(): never
    {
        throw ValidationException::withMessages([
            'budget' => "Budget {$this->budget()->id} is archived and can no longer be changed. Archiving cannot be undone, but you can still delete it.",
        ]);
    }
}
