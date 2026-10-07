<?php

namespace App\Services\MonthlySummary;

use App\Models\MonthlySummary;
use App\Models\User;
use App\Support\Figures;
use Illuminate\Support\Arr;

/**
 * Turns a frozen summary into what the monthly email says: a headline, up to
 * three percentage tiles and a line about what waits in the report.
 *
 * No absolute amount ever reaches the email. It sits in an inbox for years,
 * syncs to every device, shows on lock screens and gets forwarded, so it talks
 * in percentages and counts and points into the app, where
 * {@see ReportPresenter} prints every figure in full.
 */
class EmailPresenter
{
    /**
     * A strip, not a table: the headline already carries the savings rate.
     */
    private const MAX_TILES = 3;

    public function __construct(
        private ReportPresenter $report,
        private AchievementsSection $achievements,
    ) {}

    /**
     * @return array{monthName: string, headline: string, lede: string, kpis: list<array{value: string, label: string, sub: string, tone: ?string}>, analysisTeaser: string, inside: string}
     */
    public function present(User $user, MonthlySummary $summary, string $locale, bool $pro): array
    {
        $monthName = $summary->periodStart()->locale($locale)->isoFormat('MMMM');

        return [
            'monthName' => $monthName,
            'headline' => $this->headline($summary, $locale, $monthName),
            'lede' => $this->report->lede($summary, $locale),
            'kpis' => $this->kpis($summary, $locale),
            'analysisTeaser' => $this->analysisTeaser($summary, $locale, $monthName),
            'inside' => $this->inside($user, $summary, $locale, $pro, $monthName),
        ];
    }

    /**
     * The report's headline when the month saved something. When it did not,
     * the report names the shortfall in money and the email says it as a share
     * of what came in instead.
     */
    private function headline(MonthlySummary $summary, string $locale, string $monthName): string
    {
        $rate = (float) $summary->figure('cashflow.savings_rate', 0);

        if ($rate > 0) {
            return $this->report->headline($summary, $locale);
        }

        // Zero is a month with no income to measure against, or one that broke
        // exactly even: either way there is no percentage worth leading with.
        if ($rate == 0) {
            return __('This is how your :month went.', ['month' => $monthName]);
        }

        return __('You spent :rate more than you earned in :month.', [
            'rate' => Figures::percent(abs($rate), $locale),
            'month' => $monthName,
        ]);
    }

    /**
     * @return list<array{value: string, label: string, sub: string, tone: ?string}>
     */
    private function kpis(MonthlySummary $summary, string $locale): array
    {
        $tiles = array_filter([
            $this->netWorthTile($summary, $locale),
            $this->spendingTile($summary, $locale),
            $this->budgetsTile($summary, $locale),
            $this->goalTile($summary, $locale),
        ]);

        return array_slice(array_values($tiles), 0, self::MAX_TILES);
    }

    /**
     * Only against a positive base: the change is divided by the absolute
     * previous value, so from a negative net worth its sign would mislead.
     *
     * @return array{value: string, label: string, sub: string, tone: ?string}|null
     */
    private function netWorthTile(MonthlySummary $summary, string $locale): ?array
    {
        if (! $summary->figure('has_history', false) || (int) $summary->figure('net_worth.previous', 0) <= 0) {
            return null;
        }

        $change = (float) $summary->figure('net_worth.diff_percent', 0);

        return $this->changeTile($summary, $locale, $change, __('Net worth'), $this->tone($change));
    }

    /**
     * @return array{value: string, label: string, sub: string, tone: ?string}|null
     */
    private function spendingTile(MonthlySummary $summary, string $locale): ?array
    {
        if (! $summary->figure('has_history', false) || (int) $summary->figure('cashflow.previous.expense', 0) <= 0) {
            return null;
        }

        $change = (float) $summary->figure('cashflow.expense_change_percent', 0);

        // Spending less is the good news, so the colours run the other way.
        return $this->changeTile($summary, $locale, $change, __('Spending'), $this->tone(-$change));
    }

    /**
     * @return array{value: string, label: string, sub: string, tone: ?string}|null
     */
    private function budgetsTile(MonthlySummary $summary, string $locale): ?array
    {
        $total = (int) $summary->figure('budgets.total', 0);

        if ($total <= 0) {
            return null;
        }

        return [
            'value' => __(':met of :total', [
                'met' => Figures::count((int) $summary->figure('budgets.met', 0), $locale),
                'total' => Figures::count($total, $locale),
            ]),
            'label' => __('Budgets'),
            'sub' => __('met'),
            'tone' => null,
        ];
    }

    /**
     * @return array{value: string, label: string, sub: string, tone: ?string}|null
     */
    private function goalTile(MonthlySummary $summary, string $locale): ?array
    {
        $goal = $summary->figure('goal');

        if ($goal === null) {
            return null;
        }

        return [
            'value' => Figures::percent((float) $goal['percent'], $locale),
            'label' => (string) $goal['name'],
            'sub' => __('of the goal'),
            'tone' => null,
        ];
    }

    /**
     * @return array{value: string, label: string, sub: string, tone: ?string}
     */
    private function changeTile(MonthlySummary $summary, string $locale, float $change, string $label, ?string $tone): array
    {
        return [
            'value' => Figures::percent($change, $locale, signed: $change != 0),
            'label' => $label,
            'sub' => __('vs :month', ['month' => $summary->periodStart()->subMonth()->locale($locale)->isoFormat('MMMM')]),
            'tone' => $tone,
        ];
    }

    private function tone(float $goodness): ?string
    {
        return match (true) {
            $goodness > 0 => 'good',
            $goodness < 0 => 'bad',
            default => null,
        };
    }

    /**
     * What the Pro reader's analysis is about, without a word of it: the
     * analysis quotes amounts, so it stays in the report.
     *
     * It never promises the goal projection, which the model only writes in
     * some months.
     */
    private function analysisTeaser(MonthlySummary $summary, string $locale, string $monthName): string
    {
        $rate = (float) $summary->figure('cashflow.savings_rate', 0);

        if ($rate > 0) {
            return __('Your :month analysis, written by AI, is waiting in the report: what moved that :rate and what is going to repeat next month.', [
                'month' => $monthName,
                'rate' => Figures::percent($rate, $locale),
            ]);
        }

        if ($rate < 0) {
            return __('Your :month analysis, written by AI, is waiting in the report: what made you spend more than you earned and what is going to repeat next month.', ['month' => $monthName]);
        }

        return __('Your :month analysis, written by AI, is waiting in the report: what shaped the month and what is going to repeat next month.', ['month' => $monthName]);
    }

    /**
     * The line under the main button, built from what the report really holds
     * this month so it never promises an empty section.
     */
    private function inside(User $user, MonthlySummary $summary, string $locale, bool $pro, string $monthName): string
    {
        $medals = $this->achievements->earnedCount($user, $summary);
        $todos = count($this->report->todos($summary, $locale, $pro));

        $items = array_values(array_filter([
            __('every figure in detail'),
            $medals > 0 ? trans_choice(':count new medal|:count new medals', $medals, ['count' => Figures::count($medals, $locale)]) : null,
            $todos > 0 ? trans_choice('one thing to close :month|:count things to close :month', $todos, ['count' => Figures::count($todos, $locale), 'month' => $monthName]) : null,
        ]));

        return __('Inside: :items.', ['items' => Arr::join($items, ', ', ' '.__('and').' ')]);
    }
}
