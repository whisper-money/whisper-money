<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\SavingsGoal;
use Illuminate\Validation\ValidationException;

/**
 * For the `/api/v1/savings-goals/{savings_goal}` write requests: the goal is
 * looked up in the request's space, and only the member who created it may
 * change it (see ResolvesOwnedRouteModel). An archived goal is read-only for
 * good (SavingsGoalPolicy), which is a 422 rather than an ownership refusal.
 */
trait ResolvesRouteSavingsGoal
{
    use ResolvesOwnedRouteModel {
        failedAuthorization as refuseNotOwner;
    }

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

        if ($goal->user_id === $this->user()?->id && $goal->isArchived()) {
            throw ValidationException::withMessages([
                'savings_goal' => "Savings goal {$goal->id} is archived and can no longer be changed. Archiving and deleting goals happen in the Whisper Money app.",
            ]);
        }

        $this->refuseNotOwner();
    }
}
