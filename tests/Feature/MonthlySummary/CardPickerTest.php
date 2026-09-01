<?php

use App\Enums\MonthlySummaryCard;
use App\Services\MonthlySummary\CardPicker;

/*
 * Which card a month earns.
 *
 * The order is the argument: the first rule that holds wins, ranked by how much
 * the figure is worth publishing. A streak leads because it is the only thing on
 * the list that takes months to earn, and the spending split closes it because
 * there is always a spending split — so nobody is left without a card.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function payload(array $overrides = []): array
{
    return array_replace_recursive([
        'has_history' => true,
        'streak_months' => 0,
        'best_savings_rate_in_year' => false,
        'cashflow' => ['income' => 300000, 'savings_rate' => 12.0],
        'categories' => ['total' => 200000, 'count' => 8, 'top_share' => 60.0],
        'net_worth' => ['current' => 1000000, 'year_percent' => 2.0],
        'goal' => null,
    ], $overrides);
}

it('leads with a streak of three months or more', function (): void {
    expect(app(CardPicker::class)->pick(payload(['streak_months' => 3])))
        ->toBe(MonthlySummaryCard::Streak);
});

it('does not call two months a streak', function (): void {
    expect(app(CardPicker::class)->pick(payload(['streak_months' => 2])))
        ->toBe(MonthlySummaryCard::SpendingSplit);
});

it('picks the savings rate when it is the best of the year', function (): void {
    expect(app(CardPicker::class)->pick(payload(['best_savings_rate_in_year' => true])))
        ->toBe(MonthlySummaryCard::SavingsRate);
});

it('picks a goal that crossed a ten-percent mark since last month', function (): void {
    $picked = app(CardPicker::class)->pick(
        payload(['goal' => ['name' => 'Japan', 'percent' => 62.0]]),
        previousGoalPercent: 58.0,
    );

    expect($picked)->toBe(MonthlySummaryCard::SavingsGoal);
});

it('ignores a goal that only crept forward', function (): void {
    $picked = app(CardPicker::class)->pick(
        payload(['goal' => ['name' => 'Japan', 'percent' => 62.0]]),
        previousGoalPercent: 61.0,
    );

    expect($picked)->toBe(MonthlySummaryCard::SpendingSplit);
});

it('picks a finished goal even with nothing to compare against', function (): void {
    expect(app(CardPicker::class)->pick(payload(['goal' => ['name' => 'Japan', 'percent' => 100.0]])))
        ->toBe(MonthlySummaryCard::SavingsGoal);
});

it('picks net worth when it grew more than a tenth over the year', function (): void {
    expect(app(CardPicker::class)->pick(payload(['net_worth' => ['current' => 1000000, 'year_percent' => 18.4]])))
        ->toBe(MonthlySummaryCard::NetWorth);
});

it('falls back to the spending split, which every month has', function (): void {
    expect(app(CardPicker::class)->pick(payload()))->toBe(MonthlySummaryCard::SpendingSplit);
});

it('offers no card at all when nothing moved', function (): void {
    $picked = app(CardPicker::class)->pick(payload([
        'cashflow' => ['income' => 0, 'savings_rate' => 0.0],
        'categories' => ['total' => 0, 'count' => 0, 'top_share' => 0.0],
    ]));

    expect($picked)->toBeNull();
});

it('only offers cards a first month can actually draw', function (): void {
    // Streak, net worth and goal progress all need a month before this one.
    $picked = app(CardPicker::class)->pick(payload([
        'has_history' => false,
        'streak_months' => 5,
        'net_worth' => ['current' => 1000000, 'year_percent' => 40.0],
    ]));

    expect($picked)->toBe(MonthlySummaryCard::SpendingSplit);
});

it('lists the rest as alternatives, without the one already picked', function (): void {
    $payload = payload(['streak_months' => 4, 'goal' => ['name' => 'Japan', 'percent' => 62.0]]);
    $picker = app(CardPicker::class);
    $chosen = $picker->pick($payload);

    $alternatives = $picker->alternatives($payload, $chosen);

    expect($alternatives)->not->toContain($chosen)
        ->and($alternatives)->toContain(MonthlySummaryCard::SpendingSplit)
        ->and($alternatives)->toContain(MonthlySummaryCard::SavingsGoal);
});

it('leaves out alternatives with no data behind them', function (): void {
    $payload = payload(['best_savings_rate_in_year' => true]);

    $alternatives = app(CardPicker::class)->alternatives($payload, MonthlySummaryCard::SavingsRate);

    expect($alternatives)->not->toContain(MonthlySummaryCard::SavingsGoal)
        ->and($alternatives)->not->toContain(MonthlySummaryCard::Streak);
});
