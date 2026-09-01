<?php

namespace App\Services\MonthlySummary;

use App\Enums\MonthlySummaryCard;
use App\Enums\MonthlySummaryFormat;
use App\Models\MonthlySummary;
use App\Support\Figures;
use Carbon\Carbon;

/**
 * Turns a frozen summary into the view data one card needs.
 *
 * All the copy lives here rather than in the template, because it is written in
 * the first person — the reader is the one posting it — and it has to be
 * translated. Everything it emits is relative: percentages, counts, streaks and
 * month names. No amount ever reaches a card.
 */
class CardPresenter
{
    /**
     * Shades used for the spending split, darkest first, matching the app's
     * monochrome chart palette.
     */
    private const SPLIT_SHADES = ['#18181b', '#52525b', '#a1a1aa', '#e4e4e7'];

    /**
     * Viewbox width the net worth path is drawn into.
     */
    private const LINE_VIEW_WIDTH = 900;

    /**
     * @return array<string, mixed>
     */
    public function viewData(MonthlySummary $summary, MonthlySummaryCard $card, MonthlySummaryFormat $format, bool $pro): array
    {
        $locale = app()->getLocale();
        $month = $summary->periodStart();

        return [
            'summary' => $summary,
            'card' => $card,
            'format' => $format,
            'pro' => $pro,
            'monthLabel' => $this->monthLabel($month, $locale),
            'heroSize' => $card === MonthlySummaryCard::Streak ? 340 : 268,
            ...$this->contentFor($card, $summary, $locale, $month),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contentFor(MonthlySummaryCard $card, MonthlySummary $summary, string $locale, Carbon $month): array
    {
        return match ($card) {
            MonthlySummaryCard::SavingsRate => $this->savingsRate($summary, $locale, $month),
            MonthlySummaryCard::Streak => $this->streak($summary, $locale, $month),
            MonthlySummaryCard::SpendingSplit => $this->spendingSplit($summary, $locale, $month),
            MonthlySummaryCard::NetWorth => $this->netWorth($summary, $locale, $month),
            MonthlySummaryCard::SavingsGoal => $this->savingsGoal($summary, $locale),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function savingsRate(MonthlySummary $summary, string $locale, Carbon $month): array
    {
        return [
            'kicker' => __('Savings rate'),
            'hero' => Figures::percent((float) $summary->figure('cashflow.savings_rate', 0), $locale),
            'label' => __('of what came in during :month <strong>never left.</strong>', [
                'month' => $this->monthName($month, $locale),
            ]),
            'viz' => 'viz-bars',
            'series' => $this->rateSeries($summary, $locale),
            'chips' => $this->rateChips($summary, $locale, $month),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function streak(MonthlySummary $summary, string $locale, Carbon $month): array
    {
        $best = (bool) $summary->figure('best_savings_rate_in_year', false);

        return [
            'kicker' => __('Streak'),
            'hero' => Figures::count((int) $summary->figure('streak_months', 0), $locale),
            'label' => $best
                ? __('<strong>months in a row</strong> saving, and :month was the best of them.', ['month' => $this->monthName($month, $locale)])
                : __('<strong>months in a row</strong> saving.'),
            'viz' => 'viz-pills',
            'series' => $this->streakSeries($summary, $locale),
            'chips' => [
                __('<strong>:rate</strong> saved in :month', [
                    'rate' => Figures::percent((float) $summary->figure('cashflow.savings_rate', 0), $locale),
                    'month' => $this->monthName($month, $locale),
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function spendingSplit(MonthlySummary $summary, string $locale, Carbon $month): array
    {
        return [
            'kicker' => __('Where it went'),
            'hero' => Figures::percent((float) $summary->figure('categories.top_share', 0), $locale),
            'label' => __('of what I spent in :month went on <strong>three things</strong>.', [
                'month' => $this->monthName($month, $locale),
            ]),
            'viz' => 'viz-split',
            'rows' => $this->splitRows($summary, $locale),
            'chips' => [
                __('<strong>:count categories</strong> in total', [
                    'count' => Figures::count((int) $summary->figure('categories.count', 0), $locale),
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function netWorth(MonthlySummary $summary, string $locale, Carbon $month): array
    {
        $history = (array) $summary->figure('net_worth.history', []);

        return [
            'kicker' => __('Net worth · 12 months'),
            'hero' => Figures::percent((float) $summary->figure('net_worth.year_percent', 0), $locale, signed: true),
            'label' => __('more than I had <strong>twelve months ago</strong>.'),
            'viz' => 'viz-line',
            'path' => $this->linePath($history),
            'viewWidth' => self::LINE_VIEW_WIDTH,
            'from' => $this->shortMonth($history[0]['month'] ?? null, $locale),
            'to' => $this->shortMonth($summary->period, $locale),
            'chips' => [
                __('<strong>:percent</strong> in :month alone', [
                    'percent' => Figures::percent((float) $summary->figure('net_worth.diff_percent', 0), $locale, signed: true),
                    'month' => $this->monthName($month, $locale),
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function savingsGoal(MonthlySummary $summary, string $locale): array
    {
        $eta = $summary->figure('goal.eta_month');

        return [
            'kicker' => (string) $summary->figure('goal.name'),
            'hero' => Figures::percent((float) $summary->figure('goal.percent', 0), $locale),
            'label' => __('of <strong>:goal</strong> already saved.', ['goal' => (string) $summary->figure('goal.name')]),
            'viz' => 'viz-progress',
            'percent' => min(100, (float) $summary->figure('goal.percent', 0)),
            'from' => __('start'),
            'to' => __('target'),
            'chips' => $eta === null ? [] : [
                __('on track for <strong>:month</strong>', [
                    'month' => $this->shortMonth($eta, $locale),
                ]),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function rateChips(MonthlySummary $summary, string $locale, Carbon $month): array
    {
        $chips = [];
        $streak = (int) $summary->figure('streak_months', 0);
        $budgets = (int) $summary->figure('budgets.total', 0);

        if ($streak > 1) {
            $chips[] = __('<strong>:count months</strong> in a row saving', ['count' => Figures::count($streak, $locale)]);
        }

        if ($budgets > 0) {
            $chips[] = __('<strong>:met of :total</strong> budgets met', [
                'met' => Figures::count((int) $summary->figure('budgets.met', 0), $locale),
                'total' => Figures::count($budgets, $locale),
            ]);
        }

        return $chips;
    }

    /**
     * A year of savings rates as bar heights. The last three months are shaded a
     * step darker so the eye reads the recent trend before the whole year.
     *
     * @return list<array<string, mixed>>
     */
    private function rateSeries(MonthlySummary $summary, string $locale): array
    {
        $history = (array) $summary->figure('savings_rate_history', []);
        $peak = max(1.0, max(array_map(fn (array $point): float => (float) $point['rate'], $history) ?: [1.0]));
        $lastIndex = count($history) - 1;

        return array_values(array_map(fn (int $index): array => [
            'label' => $this->shortMonth($history[$index]['month'], $locale),
            'height' => (int) round(max(0.0, (float) $history[$index]['rate']) / $peak * 100),
            'current' => $index === $lastIndex,
            'recent' => $index >= $lastIndex - 3,
        ], array_keys($history)));
    }

    /**
     * The same year, flagged by whether each month is inside the closing streak.
     *
     * @return list<array<string, mixed>>
     */
    private function streakSeries(MonthlySummary $summary, string $locale): array
    {
        $series = $this->rateSeries($summary, $locale);
        $streak = (int) $summary->figure('streak_months', 0);
        $firstInStreak = count($series) - $streak;

        return array_values(array_map(
            fn (int $index): array => [...$series[$index], 'in_streak' => $index >= $firstInStreak],
            array_keys($series),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function splitRows(MonthlySummary $summary, string $locale): array
    {
        $top = (array) $summary->figure('categories.top', []);
        $rows = [];

        foreach (array_values($top) as $index => $category) {
            $rows[] = [
                'name' => $category['name'],
                'share' => (float) $category['share'],
                'label' => Figures::percent((float) $category['share'], $locale),
                'colour' => self::SPLIT_SHADES[$index] ?? self::SPLIT_SHADES[2],
            ];
        }

        $rest = round(100 - array_sum(array_column($rows, 'share')), 1);

        if ($rest > 0.5) {
            $rows[] = [
                'name' => __('Everything else'),
                'share' => $rest,
                'label' => Figures::percent($rest, $locale),
                'colour' => self::SPLIT_SHADES[3],
            ];
        }

        return $rows;
    }

    /**
     * The net worth trend, normalised into the viewbox. Only the shape survives:
     * two identical-looking curves can be a thousand euros apart, which is
     * exactly the point.
     *
     * @param  list<array{month: string, value: int}>  $history
     */
    private function linePath(array $history): string
    {
        $values = array_map(fn (array $point): int => (int) $point['value'], $history);

        if (count($values) < 2) {
            return 'M0 150 L'.self::LINE_VIEW_WIDTH.' 150';
        }

        $low = min($values);
        $span = max(1, max($values) - $low);
        // Inset both ends: a point sitting exactly on the viewbox edge has half
        // its stroke clipped away.
        $inset = 10;
        $step = (self::LINE_VIEW_WIDTH - $inset * 2) / (count($values) - 1);
        $points = [];

        foreach ($values as $index => $value) {
            $x = round($inset + $index * $step, 1);
            $y = round(280 - ($value - $low) / $span * 250, 1);
            $points[] = ($index === 0 ? 'M' : 'L')."{$x} {$y}";
        }

        return implode(' ', $points);
    }

    private function monthLabel(Carbon $month, string $locale): string
    {
        return $month->copy()->locale($locale)->isoFormat('MMMM YYYY');
    }

    private function monthName(Carbon $month, string $locale): string
    {
        return $month->copy()->locale($locale)->isoFormat('MMMM');
    }

    private function shortMonth(?string $period, string $locale): string
    {
        if ($period === null) {
            return '';
        }

        return Carbon::createFromFormat('Y-m-d', $period.'-01')->locale($locale)->isoFormat('MMM YY');
    }
}
