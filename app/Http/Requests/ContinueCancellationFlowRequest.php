<?php

namespace App\Http\Requests;

use App\Models\CancellationSurveyResponse;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Any step after the survey: taking an offer, or confirming the cancellation.
 * The answer in the URL has to be the signed-in user's own. That it is still
 * open — a flow ends once, whichever way — is checked under a lock by the
 * controller (`SubscriptionCancellationController::takeStep()`).
 */
class ContinueCancellationFlowRequest extends FormRequest
{
    /**
     * Nothing to validate: the only input is the answer in the URL, which
     * `surveyResponse()` resolves.
     */
    public function authorize(): bool
    {
        return (bool) config('subscriptions.enabled');
    }

    /**
     * Someone else's answer is not found rather than forbidden: its id says
     * nothing about whether it exists.
     */
    public function surveyResponse(): CancellationSurveyResponse
    {
        return $this->user()
            ->cancellationSurveyResponses()
            ->findOrFail((string) $this->route('cancellationSurveyResponse'));
    }
}
