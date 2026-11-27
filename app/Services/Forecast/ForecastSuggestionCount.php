<?php

namespace App\Services\Forecast;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * How many yearly suggestions the user has not answered yet in a space, for
 * the plan's "Yearly payments found (N new)" link. Working them out reads 18 months
 * of history, so the count is kept for a week: the weekly refresh works it
 * out again, and answering the review forgets it.
 */
class ForecastSuggestionCount
{
    private const TTL_DAYS = 8;

    public function __construct(private readonly ForecastSuggestions $suggestions) {}

    public function for(User $user, string $spaceId): int
    {
        return (int) Cache::remember($this->key($user, $spaceId), now()->addDays(self::TTL_DAYS), fn (): int => $this->count($user, $spaceId));
    }

    public function refresh(User $user, string $spaceId): int
    {
        $count = $this->count($user, $spaceId);
        Cache::put($this->key($user, $spaceId), $count, now()->addDays(self::TTL_DAYS));

        return $count;
    }

    public function forget(User $user, string $spaceId): void
    {
        Cache::forget($this->key($user, $spaceId));
    }

    private function count(User $user, string $spaceId): int
    {
        return count($this->suggestions->for($user, $spaceId));
    }

    private function key(User $user, string $spaceId): string
    {
        // "yearly": counts cached before the review dropped its recurring step counted those too.
        return "forecast:new-yearly-suggestions:{$user->id}:{$spaceId}";
    }
}
