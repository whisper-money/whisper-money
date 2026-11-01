<?php

namespace App\Http\Requests;

use App\Enums\FreePlanSurveyReason;
use App\Enums\PaywallGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFreePlanSurveyResponseRequest extends FormRequest
{
    /**
     * Only someone the paywall can be holding: a signed-in user on an instance
     * that sells plans, who is not already paying.
     */
    public function authorize(): bool
    {
        return config('subscriptions.enabled')
            && $this->user()?->hasProPlan() === false;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(FreePlanSurveyReason::class)],
            'details' => ['nullable', 'string', 'max:1000'],
            'gate' => ['required', Rule::enum(PaywallGate::class)],
        ];
    }

    /**
     * The answer to store. The free text only belongs to "Other reason": the
     * field is hidden for every other option, so anything left in it is stale.
     *
     * @return array{reason: FreePlanSurveyReason, details: ?string, gate: PaywallGate}
     */
    public function answer(): array
    {
        $reason = $this->enum('reason', FreePlanSurveyReason::class);
        $details = trim((string) $this->string('details'));

        return [
            'reason' => $reason,
            'details' => $reason === FreePlanSurveyReason::Other && $details !== '' ? $details : null,
            'gate' => $this->enum('gate', PaywallGate::class),
        ];
    }
}
