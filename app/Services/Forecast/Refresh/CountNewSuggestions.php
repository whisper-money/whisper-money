<?php

namespace App\Services\Forecast\Refresh;

use App\Models\User;
use App\Services\Forecast\ForecastSuggestionCount;

/**
 * Works out again, once a week, how many suggestions the user has not
 * answered in their active space, so the plan's "N new" link picks up what
 * the week's transactions revealed.
 */
class CountNewSuggestions implements ForecastRefreshStep
{
    public function __construct(private readonly ForecastSuggestionCount $count) {}

    public function run(User $user): void
    {
        $this->count->refresh($user, $user->activeSpace()->id);
    }
}
