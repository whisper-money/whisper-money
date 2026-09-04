<?php

namespace App\Services\Achievements;

use App\Enums\AchievementFigure as Figure;
use App\Enums\AchievementRarity as Rarity;
use Illuminate\Support\Collection;

/**
 * The 46 medals, in the order the progress screen draws them.
 *
 * Eleven tracks, each a ladder read left to right: the empty slots to the right
 * of what a reader has are the road ahead, not a list of failures. Keys are
 * `track.position`, so a medal keeps its identity when a threshold moves or a
 * name is reworded, and a money medal means the same rung for every currency.
 *
 * Names are English source strings; `lang/es.json` carries the rest.
 */
class Catalog
{
    /**
     * @return Collection<string, Definition> keyed by medal key
     */
    public function all(): Collection
    {
        return collect($this->definitions())->keyBy(fn (Definition $definition): string => $definition->key);
    }

    public function find(string $key): ?Definition
    {
        return $this->all()->get($key);
    }

    /**
     * The rungs of one track, in order. Read rather than written down at the
     * call site, so adding a rung to the catalog is the whole change.
     *
     * @return list<int>
     */
    public function tiers(string $track): array
    {
        return $this->all()
            ->filter(fn (Definition $definition): bool => $definition->track === $track)
            ->map(fn (Definition $definition): int => $definition->tier)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Track labels, in display order.
     *
     * @return array<string, string>
     */
    public function tracks(): array
    {
        return [
            'transactions' => __('Transactions'),
            'categorized' => __('Fully categorized'),
            'hygiene' => __('Data hygiene'),
            'monthly_saving' => __('Monthly saving'),
            'monthly_investing' => __('Monthly investing'),
            'yearly_saving' => __('Yearly saving'),
            'net_worth' => __('Net worth'),
            'safety' => __('Debt and safety'),
            'streaks' => __('Saving streaks'),
            'savings_rate' => __('Savings rate'),
            'momentum' => __('Momentum'),
        ];
    }

    /**
     * @return list<Definition>
     */
    private function definitions(): array
    {
        return [
            // Transactions recorded: the first one, then the count.
            new Definition('transactions.1', 'transactions', 1, Rarity::Common, Figure::None, 'plus', __('First transaction')),
            new Definition('transactions.2', 'transactions', 2, Rarity::Common, Figure::Count, 'receipt', __('Transactions recorded'), 50),
            new Definition('transactions.3', 'transactions', 3, Rarity::Uncommon, Figure::Count, 'receipt', __('Transactions recorded'), 250),
            new Definition('transactions.4', 'transactions', 4, Rarity::Rare, Figure::Count, 'receipt', __('Transactions recorded'), 1000),
            new Definition('transactions.5', 'transactions', 5, Rarity::Rare, Figure::Count, 'receipt', __('Transactions recorded'), 2000),
            new Definition('transactions.6', 'transactions', 6, Rarity::Epic, Figure::Count, 'receipt', __('Transactions recorded'), 5000),
            new Definition('transactions.7', 'transactions', 7, Rarity::Epic, Figure::Count, 'receipt', __('Transactions recorded'), 10000),

            // Closed months in a row with nothing left uncategorized.
            new Definition('categorized.1', 'categorized', 1, Rarity::Common, Figure::Months, 'tags', __('Fully categorized'), 1),
            new Definition('categorized.2', 'categorized', 2, Rarity::Uncommon, Figure::Months, 'tags', __('Fully categorized'), 3),
            new Definition('categorized.3', 'categorized', 3, Rarity::Uncommon, Figure::Months, 'tags', __('Fully categorized'), 6),
            new Definition('categorized.4', 'categorized', 4, Rarity::Rare, Figure::Months, 'tags', __('Fully categorized'), 12),
            new Definition('categorized.5', 'categorized', 5, Rarity::Epic, Figure::Months, 'tags', __('Fully categorized'), 24),

            // Data hygiene: one-off events, dated when they happened.
            new Definition('hygiene.1', 'hygiene', 1, Rarity::Common, Figure::None, 'calendar-check', __('First closed month')),
            new Definition('hygiene.2', 'hygiene', 2, Rarity::Common, Figure::None, 'link', __('First bank connected')),
            new Definition('hygiene.3', 'hygiene', 3, Rarity::Uncommon, Figure::None, 'layers', __('3 connected accounts')),
            new Definition('hygiene.4', 'hygiene', 4, Rarity::Uncommon, Figure::None, 'zap', __('First automation rule')),
            new Definition('hygiene.5', 'hygiene', 5, Rarity::Common, Figure::None, 'target', __('First budget')),
            new Definition('hygiene.6', 'hygiene', 6, Rarity::Rare, Figure::Money, 'flag', __('First savings goal reached')),

            // Money ladders: thresholds come from the reader's currency.
            new Definition('monthly_saving.1', 'monthly_saving', 1, Rarity::Common, Figure::Money, 'piggy-bank', __('Saved in a month')),
            new Definition('monthly_saving.2', 'monthly_saving', 2, Rarity::Uncommon, Figure::Money, 'piggy-bank', __('Saved in a month')),
            new Definition('monthly_saving.3', 'monthly_saving', 3, Rarity::Rare, Figure::Money, 'piggy-bank', __('Saved in a month')),
            new Definition('monthly_saving.4', 'monthly_saving', 4, Rarity::Epic, Figure::Money, 'piggy-bank', __('Saved in a month')),

            new Definition('monthly_investing.1', 'monthly_investing', 1, Rarity::Common, Figure::Money, 'trending-up', __('Invested in a month')),
            new Definition('monthly_investing.2', 'monthly_investing', 2, Rarity::Uncommon, Figure::Money, 'trending-up', __('Invested in a month')),
            new Definition('monthly_investing.3', 'monthly_investing', 3, Rarity::Rare, Figure::Money, 'trending-up', __('Invested in a month')),
            new Definition('monthly_investing.4', 'monthly_investing', 4, Rarity::Epic, Figure::Money, 'trending-up', __('Invested in a month')),

            new Definition('yearly_saving.1', 'yearly_saving', 1, Rarity::Common, Figure::Money, 'coins', __('Saved in a year')),
            new Definition('yearly_saving.2', 'yearly_saving', 2, Rarity::Uncommon, Figure::Money, 'coins', __('Saved in a year')),
            new Definition('yearly_saving.3', 'yearly_saving', 3, Rarity::Rare, Figure::Money, 'coins', __('Saved in a year')),
            new Definition('yearly_saving.4', 'yearly_saving', 4, Rarity::Epic, Figure::Money, 'coins', __('Saved in a year')),

            new Definition('net_worth.1', 'net_worth', 1, Rarity::Common, Figure::Money, 'landmark', __('Net worth')),
            new Definition('net_worth.2', 'net_worth', 2, Rarity::Uncommon, Figure::Money, 'landmark', __('Net worth')),
            new Definition('net_worth.3', 'net_worth', 3, Rarity::Rare, Figure::Money, 'landmark', __('Net worth')),
            new Definition('net_worth.4', 'net_worth', 4, Rarity::Epic, Figure::Money, 'landmark', __('Net worth')),
            new Definition('net_worth.5', 'net_worth', 5, Rarity::Epic, Figure::Money, 'landmark', __('Net worth')),
            new Definition('net_worth.6', 'net_worth', 6, Rarity::Epic, Figure::Money, 'landmark', __('Net worth')),
            new Definition('net_worth.7', 'net_worth', 7, Rarity::Epic, Figure::Money, 'landmark', __('Net worth')),

            // Debt and safety.
            new Definition('safety.1', 'safety', 1, Rarity::Rare, Figure::None, 'circle-check', __('Loan paid off')),
            new Definition('safety.2', 'safety', 2, Rarity::Rare, Figure::Months, 'shield-check', __('Emergency fund'), 3),
            new Definition('safety.3', 'safety', 3, Rarity::Epic, Figure::Months, 'shield-check', __('Emergency fund'), 6),

            // Streaks count from the launch month, not from the history.
            new Definition('streaks.1', 'streaks', 1, Rarity::Common, Figure::Months, 'flame', __('Saving streak'), 3),
            new Definition('streaks.2', 'streaks', 2, Rarity::Uncommon, Figure::Months, 'flame', __('Saving streak'), 6),
            new Definition('streaks.3', 'streaks', 3, Rarity::Rare, Figure::Months, 'flame', __('Saving streak'), 12),
            new Definition('streaks.4', 'streaks', 4, Rarity::Epic, Figure::Months, 'flame', __('Saving streak'), 24),

            new Definition('savings_rate.1', 'savings_rate', 1, Rarity::Common, Figure::Percent, 'percent', __('Savings rate'), 20),
            new Definition('savings_rate.2', 'savings_rate', 2, Rarity::Uncommon, Figure::Percent, 'percent', __('Savings rate'), 30),
            new Definition('savings_rate.3', 'savings_rate', 3, Rarity::Rare, Figure::Percent, 'percent', __('Savings rate'), 50),
            new Definition('savings_rate.4', 'savings_rate', 4, Rarity::Epic, Figure::Percent, 'percent', __('Savings rate'), 75),

            new Definition('momentum.1', 'momentum', 1, Rarity::Uncommon, Figure::Money, 'arrow-up-right', __('Beat your 6-month average')),
            new Definition('momentum.2', 'momentum', 2, Rarity::Rare, Figure::Percent, 'chart-line', __('Net worth in a year'), 10),
        ];
    }
}
