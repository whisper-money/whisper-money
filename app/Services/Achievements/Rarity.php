<?php

namespace App\Services\Achievements;

use App\Models\Achievement;
use Illuminate\Support\Facades\Cache;

/**
 * How many members actually hold each medal.
 *
 * The tier on a medal is assigned by hand and never moves. This is the other
 * number, shown beside it inside the app only: a real share of the people who
 * have been evaluated. Below a floor of them it is not shown at all, because
 * "50% of members" over four people is a sentence that means nothing.
 *
 * One grouped query for everybody, cached: it is the same answer for every
 * reader and it moves once a night.
 */
class Rarity
{
    private const CACHE_KEY = 'achievements:shares';

    private const CACHE_MINUTES = 60;

    /**
     * The share of evaluated members holding each medal, as a percentage, or an
     * empty map while there are too few of them to say.
     *
     * @return array<string, float>
     */
    public function shares(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_MINUTES), function (): array {
            $evaluated = Achievement::query()->distinct()->count('user_id');

            if ($evaluated < (int) config('achievements.rarity_floor')) {
                return [];
            }

            return Achievement::query()
                ->selectRaw('`key`, count(distinct user_id) as holders')
                ->groupBy('key')
                ->pluck('holders', 'key')
                ->map(fn (int $holders): float => round(($holders / $evaluated) * 100, 1))
                ->all();
        });
    }
}
