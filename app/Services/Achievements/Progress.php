<?php

namespace App\Services\Achievements;

use App\Models\Achievement;
use App\Models\MonthlySummary;
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
 *
 * The one exception is the next medal of each track, the first rung nobody has
 * earned yet. A wall of thirteen identical silhouettes says nothing about what
 * there is to chase, so that one arrives named, with its pictogram and the
 * figure to reach, and — where {@see Standing} can read the figure cheaply —
 * how far along the reader already is.
 */
class Progress
{
    public function __construct(
        private Catalog $catalog,
        private Ladders $ladders,
        private Presenter $presenter,
        private Rarity $rarity,
        private Standing $standing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $earned = $user->achievements()->get()->keyBy('key');
        $currency = $this->ladders->currencyFor($user->currency_code);
        $shares = $this->rarity->shares();
        // Read once: the overview wants the month it started, the saving track
        // wants the number, and both are the same row.
        $report = $this->lastReport($user);

        return [
            'currency' => $currency,
            'overview' => $this->overview($earned, $report),
            'tracks' => $this->tracks($earned, $currency, $shares, $this->standing->for($user, $report)),
        ];
    }

    private function lastReport(User $user): ?MonthlySummary
    {
        return $user->monthlySummaries()
            ->whereNotNull('sent_at')
            ->orderByDesc('period')
            ->first();
    }

    /**
     * @param  Collection<string, Achievement>  $earned
     * @return array<string, mixed>
     */
    private function overview(Collection $earned, ?MonthlySummary $report): array
    {
        $latest = $earned->sortByDesc('achieved_on')->first();

        return [
            'unlocked' => $earned->count(),
            'total' => $this->catalog->all()->count(),
            'streak' => $this->activeStreak($report),
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
    private function activeStreak(?MonthlySummary $report): ?array
    {
        $months = (int) ($report?->figure('streak_months') ?? 0);

        if ($report === null || $months < 1) {
            return null;
        }

        return [
            'months' => $months,
            'since' => $report->periodStart()->copy()->subMonths($months - 1)->toDateString(),
        ];
    }

    /**
     * @param  Collection<string, Achievement>  $earned
     * @param  array<string, float>  $shares
     * @param  array<string, int>  $standing
     * @return list<array<string, mixed>>
     */
    private function tracks(Collection $earned, string $currency, array $shares, array $standing): array
    {
        $byTrack = $this->catalog->all()->groupBy(fn (Definition $definition): string => $definition->track);

        return collect($this->catalog->tracks())
            ->map(function (string $label, string $track) use ($byTrack, $earned, $currency, $shares, $standing): array {
                /** @var Collection<int, Definition> $definitions */
                $definitions = $byTrack->get($track, collect());

                // The catalog is already in tier order, so the first rung that
                // is not earned is the one to aim at. A finished track has none.
                $next = $definitions->first(fn (Definition $definition): bool => ! $earned->has($definition->key))?->key;

                $medals = $definitions
                    ->map(fn (Definition $definition): array => $this->medal(
                        $definition,
                        $earned->get($definition->key),
                        $definition->key === $next,
                        $currency,
                        $shares,
                        $standing,
                    ))
                    ->values()
                    ->all();

                return [
                    'key' => $track,
                    'label' => $label,
                    'note' => $this->note($track),
                    'unlocked' => $definitions->filter(fn (Definition $definition): bool => $earned->has($definition->key))->count(),
                    'medals' => $medals,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, float>  $shares
     * @param  array<string, int>  $standing
     * @return array<string, mixed>
     */
    private function medal(Definition $definition, ?Achievement $earned, bool $next, string $currency, array $shares, array $standing): array
    {
        // A medal still to come is a silhouette: no name, no pictogram, no
        // figure. The exception is the next one on its track, which is the
        // thing the reader is working towards and says nothing at all as three
        // question marks. Nothing has happened for it yet, so it carries no
        // date and nothing reached.
        $shown = $earned !== null || $next;
        $figure = $shown ? $this->presenter->milestone($definition, $currency) : null;

        return [
            'key' => $definition->key,
            'rarity' => $definition->rarity->value,
            'share' => $shares[$definition->key] ?? null,
            'state' => $earned !== null ? 'earned' : ($next ? 'next' : 'locked'),
            'name' => $shown ? $definition->name : null,
            'icon' => $shown ? $definition->icon : null,
            'figure' => $figure,
            'reached' => $earned === null ? null : $this->presenter->reached($earned),
            'achieved_on' => $earned?->achieved_on->toDateString(),
            'progress' => $next ? $this->progress($definition, $figure, $standing) : null,
        ];
    }

    /**
     * How far along the reader is towards the next medal, for the tracks whose
     * figure {@see Standing} can read without building a history.
     *
     * @param  array{type: string, value: int|float, currency: ?string}|null  $figure
     * @param  array<string, int>  $standing
     * @return array{now: int, goal: int|float, unlocking: bool}|null
     */
    private function progress(Definition $definition, ?array $figure, array $standing): ?array
    {
        $now = $standing[$definition->track] ?? null;

        // No figure means no number to reach — the first transaction is an
        // event, not a count — and so nothing to draw a bar against.
        if ($now === null || $figure === null) {
            return null;
        }

        return [
            'now' => $now,
            'goal' => $figure['value'],
            // The sweep runs at night, so a reader crossing a threshold today
            // stands past it with the medal still locked. Say that, rather than
            // trim the figure back to the goal and call it done.
            'unlocking' => $now >= $figure['value'],
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
