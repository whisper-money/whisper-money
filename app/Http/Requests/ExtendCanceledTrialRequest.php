<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Taking the trial extension the `trial_ending_canceled` email offers. There
 * is nothing to choose: the length is fixed and the subscription is the
 * user's own `default` one, which the controller checks.
 */
class ExtendCanceledTrialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('subscriptions.enabled');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
