<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ChooseFreePlanRequest extends FormRequest
{
    /**
     * This is the paywall's exit, so it is only for someone the paywall is
     * actually holding: not a subscriber, not a self-hosted instance with
     * `subscriptions.enabled` off, and not before the delay after onboarding
     * is up. That last one is enforced here and not only by hiding the button,
     * because the paywall is the screen people poke at when they are stuck.
     */
    public function authorize(): bool
    {
        /** @var ?User $user */
        $user = $this->user();

        return config('subscriptions.enabled')
            && $user !== null
            && ! $user->hasProPlan()
            && $user->canEscapeToFreePlan();
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
