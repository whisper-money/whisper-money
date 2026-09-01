<?php

namespace App\Services\MonthlySummary;

use App\Models\MonthlySummary;
use App\Support\Figures;
use App\Support\Money;
use Carbon\Carbon;

/**
 * Turns a frozen summary into the sentences and micro-charts the email prints.
 *
 * One sentence per figure, each with a small chart beside it, is the shape the
 * design settled on. Rows with nothing to say are dropped rather than printed as
 * zeroes, which is also what makes the first-month email work: it is this same
 * list with the comparative rows absent.
 *
 * Amounts are formatted with {@see Money::formatIn()} so the email prints them
 * the way the app does — a reader has to be able to reconcile the two.
 */
class EmailPresenter
{
    /**
     * Shades used for the three-category bar, matching the app's charts.
     */
    private const SPLIT_SHADES = ['#18181b', '#52525b', '#a1a1aa'];

    /**
     * @return array<string, mixed>
     */
    public function present(MonthlySummary $summary, string $locale, bool $pro = false): array
    {
        $month = $summary->periodStart();

        return [
            'monthName' => $month->copy()->locale($locale)->isoFormat('MMMM'),
            'monthLabel' => $month->copy()->locale($locale)->isoFormat('MMMM YYYY'),
            'headline' => $this->headline($summary, $locale, $month),
            'lede' => $this->lede($summary, $locale),
            'rows' => $this->rows($summary, $locale, $month),
            'todos' => $this->todos($summary, $locale, $pro),
        ];
    }

    private function headline(MonthlySummary $summary, string $locale, Carbon $month): string
    {
        $rate = (float) $summary->figure('cashflow.savings_rate', 0);
        $monthName = $month->copy()->locale($locale)->isoFormat('MMMM');

        if ($rate <= 0) {
            return __('You spent :amount more than you earned in :month.', [
                'amount' => $this->money($summary, abs((int) $summary->figure('cashflow.net', 0))),
                'month' => $monthName,
            ]);
        }

        return __('You saved :rate of what you earned in :month.', [
            'rate' => Figures::percent($rate, $locale),
            'month' => $monthName,
        ]);
    }

    private function lede(MonthlySummary $summary, string $locale): string
    {
        if (! $summary->figure('has_history', false)) {
            return __('Your first month with us closed. From next month on this email also compares it against the one before.');
        }

        $streak = (int) $summary->figure('streak_months', 0);

        if ($streak < 2) {
            return __('Here is the month, and three things you can close in five minutes.');
        }

        if ($summary->figure('best_savings_rate_in_year', false)) {
            return __(':count months in a row in the black, and the best of them.', [
                'count' => Figures::count($streak, $locale),
            ]);
        }

        return __(':count months in a row in the black.', ['count' => Figures::count($streak, $locale)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(MonthlySummary $summary, string $locale, Carbon $month): array
    {
        $candidates = [
            $this->netWorthRow($summary, $locale, $month),
            $this->savingsRow($summary, $locale),
            $this->categoriesRow($summary, $locale),
            $this->dropRow($summary, $locale, $month),
            $this->investedRow($summary, $locale),
            $this->budgetsRow($summary, $locale),
            $this->goalRow($summary, $locale),
        ];

        return array_values(array_filter($candidates));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function netWorthRow(MonthlySummary $summary, string $locale, Carbon $month): ?array
    {
        $current = (int) $summary->figure('net_worth.current', 0);

        if ($current === 0) {
            return null;
        }

        $diff = (int) $summary->figure('net_worth.diff', 0);
        $history = (array) $summary->figure('net_worth.history', []);

        return [
            'text' => $summary->figure('has_history', false) && $diff !== 0
                ? __('Your net worth is :amount, :diff :direction than when :month closed.', [
                    'amount' => $this->strong($this->money($summary, $current)),
                    'diff' => $this->strong($this->money($summary, abs($diff))),
                    'direction' => $diff > 0 ? __('more') : __('less'),
                    'month' => $month->copy()->subMonth()->locale($locale)->isoFormat('MMMM'),
                ])
                : __('Your net worth is :amount.', ['amount' => $this->strong($this->money($summary, $current))]),
            'viz' => 'sparkline',
            'data' => [
                'points' => $this->normalise(array_map(fn (array $point): int => (int) $point['value'], $history)),
                'left' => $this->shortMonth($history[0]['month'] ?? null, $locale),
                'right' => $this->shortMonth($summary->period, $locale),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function savingsRow(MonthlySummary $summary, string $locale): ?array
    {
        $income = (int) $summary->figure('cashflow.income', 0);
        $net = (int) $summary->figure('cashflow.net', 0);

        if ($income <= 0 || $net <= 0) {
            return null;
        }

        return [
            'text' => __('That saving is :saved of the :income that came in.', [
                'saved' => $this->strong($this->money($summary, $net)),
                'income' => $this->strong($this->money($summary, $income)),
            ]),
            'viz' => 'bar',
            'data' => [
                'segments' => [['width' => (float) $summary->figure('cashflow.savings_rate', 0), 'colour' => '#059669']],
                'left' => __('Saved'),
                'right' => Figures::percent((float) $summary->figure('cashflow.savings_rate', 0), $locale),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function categoriesRow(MonthlySummary $summary, string $locale): ?array
    {
        $top = array_values((array) $summary->figure('categories.top', []));
        $total = (int) $summary->figure('categories.total', 0);

        if (count($top) < 3 || $total <= 0) {
            return null;
        }

        return [
            'text' => __('Three categories took :top of the :total you spent: :names.', [
                'top' => $this->strong($this->money($summary, (int) array_sum(array_column($top, 'amount')))),
                'total' => $this->strong($this->money($summary, $total)),
                'names' => $this->list(array_column($top, 'name')),
            ]),
            'viz' => 'bar',
            'data' => [
                'segments' => array_map(fn (int $index): array => [
                    'width' => (float) $top[$index]['share'],
                    'colour' => self::SPLIT_SHADES[$index],
                ], array_keys($top)),
                'left' => __('Those three'),
                'right' => Figures::percent((float) $summary->figure('categories.top_share', 0), $locale),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dropRow(MonthlySummary $summary, string $locale, Carbon $month): ?array
    {
        $drop = $summary->figure('biggest_drop');

        if ($drop === null || ! $summary->figure('has_history', false)) {
            return null;
        }

        $before = (int) $drop['previous_amount'];
        $now = (int) $drop['amount'];

        return [
            'text' => __('You spent :percent less on :name than in :month: :now against :before.', [
                'percent' => $this->strong(Figures::percent(abs((float) $drop['change_percent']), $locale)),
                'name' => e(mb_strtolower((string) $drop['name'])),
                'month' => $month->copy()->subMonth()->locale($locale)->isoFormat('MMMM'),
                'now' => $this->strong($this->money($summary, $now)),
                'before' => $this->strong($this->money($summary, $before)),
            ]),
            'viz' => 'columns',
            'data' => [
                'columns' => [
                    ['height' => 100, 'colour' => '#e4e4e7', 'label' => $month->copy()->subMonth()->locale($locale)->isoFormat('MMM')],
                    ['height' => $before > 0 ? (int) round($now / $before * 100) : 0, 'colour' => '#059669', 'label' => $month->copy()->locale($locale)->isoFormat('MMM')],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function investedRow(MonthlySummary $summary, string $locale): ?array
    {
        $invested = $summary->figure('invested');

        if ($invested === null || (int) $invested['gain'] === 0) {
            return null;
        }

        $gain = (int) $invested['gain'];
        $value = max(1, (int) $invested['value']);

        return [
            'text' => $gain > 0
                ? __('Your investment accounts hold :gain in gains over what you put in.', ['gain' => $this->strong($this->money($summary, $gain))])
                : __('Your investment accounts are :gain below what you put in.', ['gain' => $this->strong($this->money($summary, abs($gain)))]),
            'viz' => 'bar',
            'data' => [
                'segments' => [
                    ['width' => (int) round((int) $invested['contributed'] / $value * 100), 'colour' => '#d4d4d8'],
                    ['width' => max(0, (int) round($gain / $value * 100)), 'colour' => $gain > 0 ? '#059669' : '#dc2626'],
                ],
                'left' => __('Paid in'),
                'right' => $this->money($summary, (int) $invested['value']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function budgetsRow(MonthlySummary $summary, string $locale): ?array
    {
        $total = (int) $summary->figure('budgets.total', 0);

        if ($total === 0) {
            return null;
        }

        $met = (int) $summary->figure('budgets.met', 0);
        $overspent = array_values((array) $summary->figure('budgets.overspent', []));

        return [
            'text' => $overspent === []
                ? __('You met all :total of your budgets.', ['total' => $this->strong(Figures::count($total, $locale))])
                : __('You met :met of :total budgets. You went over on :names.', [
                    'met' => $this->strong(Figures::count($met, $locale)),
                    'total' => $this->strong(Figures::count($total, $locale)),
                    'names' => $this->overspentList($summary, $overspent),
                ]),
            'viz' => 'dots',
            'data' => [
                'met' => $met,
                'over' => $total - $met,
                'left' => __(':count met', ['count' => Figures::count($met, $locale)]),
                'right' => __(':count over', ['count' => Figures::count($total - $met, $locale)]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function goalRow(MonthlySummary $summary, string $locale): ?array
    {
        $goal = $summary->figure('goal');

        if ($goal === null) {
            return null;
        }

        return [
            'text' => __(':name is at :percent: :saved of the :target you set.', [
                'name' => $this->strong((string) $goal['name']),
                'percent' => $this->strong(Figures::percent((float) $goal['percent'], $locale)),
                'saved' => $this->money($summary, (int) $goal['saved']),
                'target' => $this->money($summary, (int) $goal['target']),
            ]),
            'viz' => 'bar',
            'data' => [
                'segments' => [['width' => min(100, (float) $goal['percent']), 'colour' => '#18181b']],
                'left' => (string) $goal['name'],
                'right' => Figures::percent((float) $goal['percent'], $locale),
            ],
        ];
    }

    /**
     * The actionable half of the email: the few things that are worth five
     * minutes, each with the consequence of not doing it.
     *
     * @return list<array<string, mixed>>
     */
    private function todos(MonthlySummary $summary, string $locale, bool $pro): array
    {
        $todos = [];
        $uncategorised = (array) $summary->figure('todos.uncategorised', []);

        if ((int) ($uncategorised['count'] ?? 0) > 0) {
            $todos[] = [
                'icon' => 'tag',
                'text' => __(':count transactions in :month have no category, :amount in total. Until you sort them, the figures above are short.', [
                    'count' => $this->strong(Figures::count((int) $uncategorised['count'], $locale)),
                    'month' => $summary->periodStart()->locale($locale)->isoFormat('MMMM'),
                    'amount' => $this->money($summary, (int) $uncategorised['amount']),
                ]),
                'action' => __('Sort them out'),
                'route' => 'transactions.index',
            ];
        }

        $suggestions = (int) $summary->figure('todos.rule_suggestions.count', 0);
        $matched = (int) $summary->figure('todos.rule_suggestions.transactions', 0);

        // Applying rule suggestions is a Pro feature, so it is only actionable
        // for a Pro reader. On a free one the same count argues the upsell
        // instead, up in the locked analysis block.
        if ($pro && $suggestions > 0) {
            $todos[] = [
                'icon' => 'sparkle',
                'text' => __(':count suggested rules are waiting. Applying them categorises :transactions of your transactions on their own.', [
                    'count' => $this->strong(Figures::count($suggestions, $locale)),
                    'transactions' => $this->strong(Figures::count($matched, $locale)),
                ]),
                'action' => __('See the rules'),
                'route' => 'automation-rules.index',
            ];
        }

        foreach ($this->connectionTodos($summary, $locale) as $todo) {
            $todos[] = $todo;
        }

        return $todos;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function connectionTodos(MonthlySummary $summary, string $locale): array
    {
        $todos = [];

        foreach ((array) $summary->figure('todos.expiring_connections', []) as $connection) {
            $todos[] = [
                'icon' => 'alert',
                'text' => __('Your access to :bank expires in :days days. If it lapses, next month starts with no transactions.', [
                    'bank' => $this->strong((string) $connection['bank']),
                    'days' => $this->strong(Figures::count((int) $connection['days'], $locale)),
                ]),
                'action' => __('Renew the access'),
                'route' => 'settings.connections.index',
            ];
        }

        return $todos;
    }

    /**
     * @param  list<array<string, mixed>>  $overspent
     */
    private function overspentList(MonthlySummary $summary, array $overspent): string
    {
        return $this->list(array_map(
            fn (array $budget): string => e((string) $budget['name']).' ('.$this->strong('+'.$this->money($summary, (int) $budget['over_by'])).')',
            $overspent,
        ), escape: false);
    }

    /**
     * @param  list<string|null>  $items
     * @param  bool  $escape  false when the items are already-escaped markup
     */
    private function list(array $items, bool $escape = true): string
    {
        $items = array_values(array_filter(array_map(
            fn (?string $item): string => $escape ? e((string) $item) : (string) $item,
            $items,
        )));

        if (count($items) < 2) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' '.__('and').' '.$last;
    }

    /**
     * Normalise a series into 0-100 heights: the sparkline shows the shape, and
     * the sentence beside it carries the numbers.
     *
     * @param  list<int>  $values
     * @return list<int>
     */
    private function normalise(array $values): array
    {
        if (count($values) < 2) {
            return [50, 50];
        }

        $low = min($values);
        $span = max(1, max($values) - $low);

        return array_map(fn (int $value): int => (int) round(($value - $low) / $span * 100), $values);
    }

    private function money(MonthlySummary $summary, int $amount): string
    {
        return Money::formatIn($amount, (string) $summary->figure('currency', 'EUR'), app()->getLocale());
    }

    /**
     * Emphasis is applied here because the sentences are assembled from
     * translated fragments, and a translator should not have to carry the markup
     * through in every language.
     */
    private function strong(string $value): string
    {
        return '<strong>'.e($value).'</strong>';
    }

    private function shortMonth(?string $period, string $locale): string
    {
        if ($period === null) {
            return '';
        }

        return Carbon::createFromFormat('Y-m-d', $period.'-01')->locale($locale)->isoFormat('MMM YY');
    }
}
