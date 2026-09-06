<?php

namespace App\Services\MonthlySummary;

use App\Features\Achievements;
use App\Models\Achievement;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Services\Achievements\Catalog;
use App\Services\Achievements\Definition;
use App\Services\Achievements\Ladders;
use App\Services\Achievements\Presenter;
use App\Services\Achievements\Standing;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * The medals half of the monthly report: what the month earned, and what is
 * within reach now.
 *
 * Worked out at presentation time rather than frozen into the payload, for
 * three reasons. The payload is handed whole to the model that writes the
 * analysis, and a prompt told to use only the figures it is given would start
 * writing about medals. Nothing here needs the summary's shape to change. And
 * "how far off the next one is" is a figure of today, not of a month that
 * closed weeks ago — the reader can act on it this afternoon.
 *
 * Both halves are optional and the whole section is dropped when neither has
 * anything to say, the same way the report drops a row it has no figure for.
 */
class AchievementsSection
{
    /**
     * Three at most: this is the tail of a report, not the progress screen.
     */
    private const SUGGESTIONS = 3;

    public function __construct(
        private Catalog $catalog,
        private Ladders $ladders,
        private Presenter $presenter,
        private Standing $standing,
    ) {}

    /**
     * @return list<array{title: string, lines: list<string>}>|null null when the
     *                                                              feature is off for this reader, or when there is nothing to say
     */
    public function for(User $user, MonthlySummary $summary, string $locale): ?array
    {
        if (! Feature::for($user)->active(Achievements::class)) {
            return null;
        }

        $earned = $user->achievements()->orderBy('key')->get()->keyBy('key');
        $currency = $this->ladders->currencyFor($user->currency_code);

        $groups = array_values(array_filter([
            $this->unlocked($earned, $summary, $currency, $locale),
            $this->next($user, $earned, $summary, $currency, $locale),
        ]));

        return $groups === [] ? null : $groups;
    }

    /**
     * The medals the reported month earned.
     *
     * An exact match rather than a range: a medal is always dated to the first
     * day of the month it really happened in, so the month that closed is the
     * whole filter. The sweep runs nightly and the report goes out from the 3rd,
     * so by the time this is read the month has long been swept.
     *
     * @param  Collection<string, Achievement>  $earned
     * @return array{title: string, lines: list<string>}|null
     */
    private function unlocked(Collection $earned, MonthlySummary $summary, string $currency, string $locale): ?array
    {
        $month = $summary->periodStart();

        $lines = $earned
            ->filter(fn (Achievement $achievement): bool => $achievement->achieved_on->toDateString() === $month->toDateString())
            ->map(fn (Achievement $achievement): ?Definition => $this->catalog->find($achievement->key))
            ->filter()
            ->map(fn (Definition $definition): string => $this->earnedLine($definition, $currency, $locale))
            ->values()
            ->all();

        return $lines === [] ? null : [
            'title' => __('What you unlocked in :month', ['month' => $month->locale($locale)->isoFormat('MMMM')]),
            'lines' => $lines,
        ];
    }

    /**
     * The medals closest to falling, nearest first.
     *
     * Only the ones whose distance can be named. A suggestion without a "you
     * are this far off" is a nudge towards nothing, and the tracks whose
     * current figure only exists inside a balance walk cannot be given one.
     *
     * @param  Collection<string, Achievement>  $earned
     * @return array{title: string, lines: list<string>}|null
     */
    private function next(User $user, Collection $earned, MonthlySummary $summary, string $currency, string $locale): ?array
    {
        $figures = $this->figures($user, $summary);
        $candidates = [];

        foreach ($this->catalog->next($earned) as $track => $definition) {
            $goal = $this->presenter->milestone($definition, $currency);
            $now = $figures[$track] ?? null;

            // Nothing under way and nothing left to reach are both silence.
            // Past the threshold, the medal lands on tonight's sweep and is an
            // unlock waiting to happen rather than something to chase. At zero
            // or below, there is no distance worth naming: a month that spent
            // more than it earned is not "600 euros from saving 100", and the
            // to-dos further down are the honest thing to say to it.
            if ($goal === null || $now === null || $now <= 0 || $now >= $goal['value']) {
                continue;
            }

            $candidates[] = [
                'share' => $now / $goal['value'],
                'line' => $this->nextLine($definition, $goal, $now, $locale),
            ];
        }

        // Nearest first, measured as a share of the way there: the tracks count
        // days, months, transactions and money, and a raw remainder cannot be
        // compared across them.
        usort($candidates, fn (array $a, array $b): int => $b['share'] <=> $a['share']);

        $lines = array_column(array_slice($candidates, 0, self::SUGGESTIONS), 'line');

        return $lines === [] ? null : [
            'title' => __('What you can unlock next'),
            'lines' => $lines,
        ];
    }

    /**
     * Where the reader stands, per track, without paying for a history.
     *
     * {@see Standing} covers the five tracks that are a column or a count. The
     * money ones come off the frozen payload, which is something the progress
     * screen cannot do: it has no month in hand, and these figures were worked
     * out once when the month closed.
     *
     * @return array<string, int|float>
     */
    private function figures(User $user, MonthlySummary $summary): array
    {
        $figures = $this->standing->for($user, $summary);
        $figures['savings_rate'] = (float) $summary->figure('cashflow.savings_rate', 0);

        $currency = strtoupper((string) $summary->figure('currency', ''));

        // Money ladders are read in the reader's own currency when one exists
        // for it and in the fallback currency when it does not, while the frozen
        // figures are always in their own. Across that gap the comparison would
        // be pesos against euros, so it is not made at all.
        if ($this->ladders->currencyFor($currency) === $currency) {
            $figures['net_worth'] = (int) $summary->figure('net_worth.current', 0);
            $figures['monthly_saving'] = (int) $summary->figure('cashflow.net', 0);
        }

        return $figures;
    }

    private function earnedLine(Definition $definition, string $currency, string $locale): string
    {
        $milestone = $this->presenter->write($this->presenter->milestone($definition, $currency), $locale);
        $name = $this->strong($definition->name);

        return $milestone === null
            ? $name.'.'
            : __(':name, :milestone.', ['name' => $name, 'milestone' => e($milestone)]);
    }

    /**
     * @param  array{type: string, value: int|float, currency: ?string}  $goal
     */
    private function nextLine(Definition $definition, array $goal, int|float $now, string $locale): string
    {
        return __(':name, :milestone. :remaining to go.', [
            'name' => $this->strong($definition->name),
            'milestone' => e((string) $this->presenter->write($goal, $locale)),
            'remaining' => $this->strong((string) $this->presenter->write([...$goal, 'value' => $goal['value'] - $now], $locale)),
        ]);
    }

    /**
     * Emphasis is applied here, like the rest of the report: the sentences are
     * assembled from translated fragments and a translator should not have to
     * carry markup through in every language.
     */
    private function strong(string $value): string
    {
        return '<strong>'.e($value).'</strong>';
    }
}
