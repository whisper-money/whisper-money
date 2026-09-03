<?php

namespace App\Services\Achievements;

use App\Models\Achievement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The progress screen, assembled.
 *
 * Every medal in the catalog is sent, earned or not: the ones still to come are
 * the road ahead rather than a list of failures, and the screen draws them as
 * empty slots to the right of what has been earned. What a locked medal is
 * called stays here — the frontend gets a tier and nothing else for it.
 */
class Progress
{
    public function __construct(
        private Catalog $catalog,
        private Ladders $ladders,
        private Presenter $presenter,
        private Rarity $rarity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $earned = $user->achievements()->get()->keyBy('key');
        $currency = $this->ladders->currencyFor($user->currency_code);
        $shares = $this->rarity->shares();

        return [
            'currency' => $currency,
            'overview' => $this->overview($earned, $user),
            'tracks' => $this->tracks($earned, $currency, $shares),
        ];
    }

    /**
     * @param  Collection<string, Achievement>  $earned
     * @return array<string, mixed>
     */
    private function overview(Collection $earned, User $user): array
    {
        $latest = $earned->sortByDesc('achieved_on')->first();

        return [
            'unlocked' => $earned->count(),
            'total' => $this->catalog->all()->count(),
            'streak' => $this->activeStreak($user),
            'latest' => $latest === null ? null : [
                'name' => $this->catalog->find($latest->key)?->name,
                'achieved_on' => $latest->achieved_on->toDateString(),
            ],
        ];
    }

    /**
     * The streak as it stands, which is a live figure and not a medal: a medal
     * records that a streak happened and stays when it breaks, so the two are
     * shown apart. Read off the last report rather than recomputed, so the
     * screen and the email cannot disagree about the same number.
     *
     * @return array{months: int, since: ?string}|null
     */
    private function activeStreak(User $user): ?array
    {
        $summary = $user->monthlySummaries()
            ->whereNotNull('sent_at')
            ->orderByDesc('period')
            ->first();

        $months = (int) ($summary?->figure('streak_months') ?? 0);

        if ($summary === null || $months < 1) {
            return null;
        }

        return [
            'months' => $months,
            'since' => $summary->periodStart()->copy()->subMonths($months - 1)->toDateString(),
        ];
    }

    /**
     * @param  Collection<string, Achievement>  $earned
     * @param  array<string, float>  $shares
     * @return list<array<string, mixed>>
     */
    private function tracks(Collection $earned, string $currency, array $shares): array
    {
        $byTrack = $this->catalog->all()->groupBy(fn (Definition $definition): string => $definition->track);

        return collect($this->catalog->tracks())
            ->map(function (string $label, string $track) use ($byTrack, $earned, $currency, $shares): array {
                $medals = $byTrack->get($track, collect())
                    ->map(fn (Definition $definition): array => $this->medal($definition, $earned->get($definition->key), $currency, $shares))
                    ->values()
                    ->all();

                return [
                    'key' => $track,
                    'label' => $label,
                    'note' => $this->note($track),
                    'unlocked' => count(array_filter($medals, fn (array $medal): bool => ! $medal['locked'])),
                    'medals' => $medals,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, float>  $shares
     * @return array<string, mixed>
     */
    private function medal(Definition $definition, ?Achievement $earned, string $currency, array $shares): array
    {
        return [
            'key' => $definition->key,
            'rarity' => $definition->rarity->value,
            'share' => $shares[$definition->key] ?? null,
            'locked' => $earned === null,
            // A medal still to come is a silhouette: no name, no pictogram, no
            // figure. Only the tier, so the shape of what is left is readable.
            'name' => $earned === null ? null : $definition->name,
            'icon' => $earned === null ? null : $definition->icon,
            'figure' => $earned === null ? null : $this->presenter->milestone($definition, $currency),
            'reached' => $earned === null ? null : $this->presenter->reached($earned),
            'achieved_on' => $earned?->achieved_on->toDateString(),
        ];
    }

    /**
     * The one thing a track has to explain about itself.
     */
    private function note(string $track): ?string
    {
        if ($track !== 'streaks') {
            return null;
        }

        $from = (string) config('achievements.streaks_from');

        return __('Counting from :month on, when achievements arrived.', [
            'month' => Carbon::createFromFormat('Y-m-d', $from.'-01')
                ->locale(app()->getLocale())
                ->isoFormat('MMMM YYYY'),
        ]);
    }
}
