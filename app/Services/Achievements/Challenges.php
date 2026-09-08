<?php

namespace App\Services\Achievements;

use App\Models\User;

/**
 * The two medals the app puts in front of the reader instead of waiting to be
 * looked up: the visit streak in the header, and the pile of transactions still
 * without a category.
 *
 * This rides along with every screen, so it is deliberately not {@see Progress}:
 * that one builds all thirteen tracks, the rarity shares and the last monthly
 * report, which is a page's worth of work to answer two questions. Everything
 * here is either a column on the reader's own row or one narrow query.
 *
 * The prompt is the exception, and it pays for itself: its bar reads the
 * categorized run, a grouped read over every month the reader has, and it is
 * only asked for once the prompt is actually due.
 */
class Challenges
{
    public function __construct(
        private Catalog $catalog,
        private Presenter $presenter,
        private Standing $standing,
    ) {}

    /**
     * @return array{
     *     visit_streak: int,
     *     medals: list<array<string, mixed>>,
     *     uncategorized: array{count: int, medal: array<string, mixed>|null}|null,
     * }
     */
    public function for(User $user): array
    {
        $next = $this->catalog->next($user->achievements()->pluck('key')->flip());

        return [
            'visit_streak' => (int) $user->visit_streak,
            // Both bars read the longest run rather than the live one, exactly
            // as {@see Standing} does and for the same reason: the medal is
            // awarded on the peak, so a bar following the live streak would
            // fall back without the medal moving any further away. Only the
            // number on the pill is the live run.
            'medals' => collect([
                $this->medal($user, $next->get('visits'), (int) $user->longest_visit_streak),
                $this->medal($user, $next->get('visit_weeks'), (int) $user->longest_visit_week_streak),
            ])->filter()->values()->all(),
            'uncategorized' => $this->uncategorized($user, $next->get('categorized')),
        ];
    }

    /**
     * The rung a track is working towards, named and with the figure to reach.
     * Null on a finished track, which is what a reader past the last rung has:
     * nothing left to aim at, and a number that keeps going up anyway.
     *
     * @return array<string, mixed>|null
     */
    private function medal(User $user, ?Definition $definition, ?int $now): ?array
    {
        if ($definition === null) {
            return null;
        }

        $figure = $this->presenter->milestone($definition, (string) $user->currency_code);

        return [
            'track' => $definition->track,
            'rarity' => $definition->rarity->value,
            'icon' => $definition->icon,
            'name' => $definition->name,
            'figure' => $figure,
            'progress' => $this->presenter->progress($figure, $now),
        ];
    }

    /**
     * What is left to categorize in the month in progress, or null when there
     * is nothing to ask for.
     *
     * The month in progress on purpose: {@see Standing} reads closed months
     * only, because that is what the nightly sweep awards on, and the whole
     * point of the prompt is the month nobody has tidied yet.
     *
     * Whether the prompt is due is settled here rather than sent as a date for
     * the frontend to compare against its own clock: one answer, one place, and
     * the run below is never paid for by a reader who has already said "not now".
     *
     * @return array{count: int, medal: array<string, mixed>|null}|null
     */
    private function uncategorized(User $user, ?Definition $next): ?array
    {
        $snoozedUntil = $user->uncategorized_prompt_snoozed_until;

        if ($snoozedUntil !== null && $snoozedUntil->isFuture()) {
            return null;
        }

        $count = $user->transactions()
            ->whereNull('category_id')
            ->where('transaction_date', '>=', now()->startOfMonth())
            ->where('transaction_date', '<', now()->startOfMonth()->addMonth())
            ->count();

        if ($count === 0) {
            return null;
        }

        return [
            'count' => $count,
            'medal' => $this->medal($user, $next, $this->standing->categorized($user)),
        ];
    }
}
