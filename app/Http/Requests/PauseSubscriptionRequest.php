<?php

namespace App\Http\Requests;

use App\Services\Subscriptions\CancellationOffers;
use Illuminate\Validation\Rule;

/**
 * The cancel flow's pause offer, taken: how many months to pause for.
 */
class PauseSubscriptionRequest extends ContinueCancellationFlowRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'months' => ['required', 'integer', Rule::in(CancellationOffers::PAUSE_MONTHS)],
        ];
    }

    public function months(): int
    {
        return $this->integer('months');
    }
}
