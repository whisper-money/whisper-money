<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\SavingsGoal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * For the `/api/v1/savings-goals/{savings_goal}` write requests: the goal is
 * looked up among the caller's own in the request's space (see
 * ResolvesOwnedRouteModel). An archived goal is read-only for good
 * (SavingsGoalPolicy), which is a 422 rather than a refusal.
 */
trait ResolvesRouteSavingsGoal
{
    use ResolvesOwnedRouteModel;

    public function savingsGoal(): SavingsGoal
    {
        /** @var SavingsGoal */
        return $this->routeModel();
    }

    protected function routeModelBinding(): array
    {
        return [SavingsGoal::class, 'savings_goal'];
    }

    protected function failedAuthorization(): never
    {
        $goal = $this->savingsGoal();

        if ($goal->isArchived()) {
            throw ValidationException::withMessages([
                'savings_goal' => "Savings goal {$goal->id} is archived and can no longer be changed. Archiving and deleting goals happen in the Whisper Money app.",
            ]);
        }

        throw new AuthorizationException;
    }
}
