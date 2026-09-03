<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ChooseFreePlanRequest extends FormRequest
{
    /**
     * The delay after onboarding is enforced here and not only by hiding the
     * button: the paywall is the screen people poke at when they are stuck.
     */
    public function authorize(): bool
    {
        /** @var ?User $user */
        $user = $this->user();

        return (bool) $user?->canEscapeToFreePlan();
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
