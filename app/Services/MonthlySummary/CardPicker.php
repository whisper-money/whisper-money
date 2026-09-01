<?php

namespace App\Services\MonthlySummary;

use App\Enums\MonthlySummaryCard;

/**
 * Picks the shareable card for a month.
 *
 * Five types would be five different emails if every user had to choose, so the
 * choice is made for them: the first rule that holds wins, ordered by how much
 * the figure is worth publishing. A streak leads because it is the only one on
 * the list that takes months to earn; the spending split closes the list because
 * there is always a spending split, so nobody ends up without a card.
 *
 * The other four stay available in the email's picker.
 */
class CardPicker
{
    /**
     * Months of positive cashflow before a streak is worth a card of its own.
     */
    private const STREAK_THRESHOLD = 3;

    /**
     * Yearly net worth growth that earns the net worth card, in percent.
     */
    private const NET_WORTH_THRESHOLD = 10.0;

    /**
     * @param  array<string, mixed>  $payload
     * @param  ?float  $previousGoalPercent  the same goal's percentage last month, if it was reported
     */
    public function pick(array $payload, ?float $previousGoalPercent = null): ?MonthlySummaryCard
    {
        if (! $this->hasAnythingToSay($payload)) {
            return null;
        }

        $hasHistory = (bool) data_get($payload, 'has_history', false);

        foreach ($this->ranked($payload, $previousGoalPercent) as $card => $holds) {
            $candidate = MonthlySummaryCard::from($card);

            if (! $holds || (! $hasHistory && ! $candidate->worksWithoutHistory())) {
                continue;
            }

            return $candidate;
        }

        return MonthlySummaryCard::SpendingSplit;
    }

    /**
     * The remaining cards, in the same order, for the email's picker.
     *
     * @param  array<string, mixed>  $payload
     * @return list<MonthlySummaryCard>
     */
    public function alternatives(array $payload, ?MonthlySummaryCard $chosen): array
    {
        $hasHistory = (bool) data_get($payload, 'has_history', false);

        return array_values(array_filter(
            MonthlySummaryCard::cases(),
            fn (MonthlySummaryCard $card): bool => $card !== $chosen
                && ($hasHistory || $card->worksWithoutHistory())
                && $this->canDraw($card, $payload),
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, bool>
     */
    private function ranked(array $payload, ?float $previousGoalPercent): array
    {
        return [
            MonthlySummaryCard::Streak->value => (int) data_get($payload, 'streak_months', 0) >= self::STREAK_THRESHOLD,
            MonthlySummaryCard::SavingsRate->value => (bool) data_get($payload, 'best_savings_rate_in_year', false),
            MonthlySummaryCard::SavingsGoal->value => $this->goalIsNewsworthy($payload, $previousGoalPercent),
            MonthlySummaryCard::NetWorth->value => (float) data_get($payload, 'net_worth.year_percent', 0) > self::NET_WORTH_THRESHOLD,
            MonthlySummaryCard::SpendingSplit->value => true,
        ];
    }

    /**
     * A goal earns the card when it finishes, or when it crosses a ten-percent
     * mark since the month before — the moments a person actually wants to post.
     *
     * @param  array<string, mixed>  $payload
     */
    private function goalIsNewsworthy(array $payload, ?float $previousGoalPercent): bool
    {
        $percent = data_get($payload, 'goal.percent');

        if ($percent === null) {
            return false;
        }

        if ($percent >= 100) {
            return true;
        }

        if ($previousGoalPercent === null) {
            return false;
        }

        return intdiv((int) $percent, 10) > intdiv((int) $previousGoalPercent, 10);
    }

    /**
     * Whether this month has the figures a card needs. Public because the card
     * route has to refuse a combination the picker would never offer: without
     * it, a hand-typed URL renders "0.0% of  already saved".
     *
     * @param  array<string, mixed>  $payload
     */
    public function canDraw(MonthlySummaryCard $card, array $payload): bool
    {
        return match ($card) {
            MonthlySummaryCard::Streak => (int) data_get($payload, 'streak_months', 0) > 0,
            MonthlySummaryCard::SavingsRate => (float) data_get($payload, 'cashflow.savings_rate', 0) > 0,
            MonthlySummaryCard::SavingsGoal => data_get($payload, 'goal.percent') !== null,
            MonthlySummaryCard::NetWorth => (int) data_get($payload, 'net_worth.current', 0) !== 0,
            MonthlySummaryCard::SpendingSplit => (int) data_get($payload, 'categories.total', 0) > 0,
        };
    }

    /**
     * With neither money in nor money out there is no card to draw, and a card
     * with nothing on it is worse than no card.
     *
     * @param  array<string, mixed>  $payload
     */
    private function hasAnythingToSay(array $payload): bool
    {
        return (int) data_get($payload, 'cashflow.income', 0) > 0
            || (int) data_get($payload, 'categories.total', 0) > 0;
    }
}
