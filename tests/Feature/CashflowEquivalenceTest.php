<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\CashflowEquivalenceWorld;

/*
 * Characterization test: it pins the cashflow figures every consumer returns
 * today, for one invented household, so a change in how they are added up
 * (the SQL aggregation of upstream #1162) can prove it changes nothing.
 *
 * The golden file was generated from the code before that port and is only
 * read here. Every uuid in the output is replaced by the name of what it
 * points at, and lists keep the order they came back in: the order is part of
 * what must not change.
 *
 * The queries before the port have no ORDER BY, so that order is the query
 * plan's: MySQL may read `accounts` first and each account's transactions by
 * id, or the transactions by date. The household is laid out so that both
 * give the same first-seen order of categories, which is also the order the
 * SQL aggregation promises, by (transaction_date, id): a category's first row
 * by date is also its first row on the first account that has it.
 */

beforeEach(function () {
    Http::fake();

    // Mid-March: March is the current period, February the previous one, and
    // the March budget periods are in progress.
    $this->travelTo(Carbon::parse('2026-03-18 12:00:00'));
});

test('every cashflow consumer returns the figures pinned before the SQL port', function () {
    $outputs = CashflowEquivalenceWorld::outputs(CashflowEquivalenceWorld::build());

    $golden = json_decode((string) file_get_contents(CashflowEquivalenceWorld::GOLDEN), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($outputs))->toBe(array_keys($golden));

    foreach ($golden as $call => $expected) {
        expect($outputs[$call])->toBe($expected, "The output of {$call} changed.");
    }
});

test('the pinned figures add up by hand', function () {
    $outputs = CashflowEquivalenceWorld::outputs(CashflowEquivalenceWorld::build());
    $march = $outputs['summary march']['current'];
    $sankeyIncome = collect($outputs['sankey march']['income_categories'])->keyBy('category.name');

    // Income, March, in EUR (user Main only; the household partner's rows are not theirs):
    //   salary 260000 + bonus 15001 + sublet split line 10000
    //   + salary 1234 USD with no currency on the USD account: 1234 / 1.0873 = 1134.92 -> 1135
    //   + uncategorized inflow 1111 USD: 1111 / 1.0866 = 1022.46 -> 1022
    //   + gift 4321
    //   = 291479
    expect($march['income'])->toBe(260000 + 15001 + 10000 + 1135 + 1022 + 4321);

    // Expense, March, signed (refunds net out). Lines of split transactions count on their own:
    //   rent line 50000; groceries line 10000
    //   USD split -10001 at 1.0837: parent -9229, dining -3076, fun -3076, shopping (residue) -3077
    //   archived account before archiving 1111 (the archive day and after are out)
    //   GBP 50% split -5001 at 0.8571: parent -5835, groceries -2918 -> 1459, dining (residue) -2917 -> -1458.5 -> 1459
    //   33% account: groceries -1001 -> 330, fun -2999 -> 990, split lines -501 and -502 (residue) -> 165 + 166
    //   shopping 2000 - refund 6500, subscription 1299, dining 4550, utilities 6789, home 1500
    //   uncategorized outflow 2345
    //   trashed account: -1000 with no currency reads as EUR, -2000 USD at 1.0873 -> 1839, both in full
    //   (the account is not loaded, so neither its currency nor its share applies)
    //   trashed category 7777: out of every figure
    //   groceries 2500 USD on a EUR account at 1.0873 -> 2299; fun 1800 EUR on a USD account
    //   two coin rows of 5 USD at 1.0873: 4.60 -> 5 each, so 10 (converted as a sum it would be 9)
    //   dining 321 in lowercase "eur" on a USD account: not converted
    //   joint account groceries 6000; pharmacy 999; groceries refund 25000
    $expectedExpense = 50000 + 10000 + 3076 + 3076 + 3077 + 1111 + 1459 + 1459 + 330 + 990 + 165 + 166
        + 2000 - 6500 + 1299 + 4550 + 6789 + 1500 + 2345 + 1000 + 1839 + 2299 + 1800 + 5 + 5 + 321 + 6000 + 999 - 25000;
    expect($march['expense'])->toBe($expectedExpense)
        ->and($march['net'])->toBe($march['income'] - $march['expense']);

    // Fixed: what Housing (Home tree), Dining out (dining) and Subscriptions (label) track:
    //   rent line 50000 + utilities 6789 + home 1500
    //   + dining 4550 + USD split dining line 3076 + GBP split dining line 1459 + lowercase eur 321
    //   (fixed beats Food's variable, and beats the Holiday label on the USD split)
    //   + subscription 1299 (fixed label beats Yearly fun's variable category)
    // Variable: Food budget (groceries) and Yearly fun (fun), and the Holiday label:
    //   groceries line 10000 + archived 1111 + GBP line 1459 + 33% 330 + USD-on-EUR 2299 + joint 6000
    //   - groceries refund 25000
    //   + fun: USD split line 3076 + 33% 990 + 33% split line 166 + trashed account 1000 + 1839 + 1800
    //   + coins 5 + 5
    //   + the USD split's shopping residue line 3077, claimed by the Holiday label on its parent
    // General: what nothing tracks, the trashed label included:
    //   shopping: 33% split line 165 + 2000 - 6500; uncategorized 2345; pharmacy 999
    expect($march['fixed_expense'])->toBe(50000 + 6789 + 1500 + 4550 + 3076 + 1459 + 321 + 1299)
        ->and($march['variable_expense'])->toBe(10000 + 1111 + 1459 + 330 + 2299 + 6000 - 25000 + 3076 + 990 + 166 + 1000 + 1839 + 1800 + 5 + 5 + 3077)
        ->and($march['general_expense'])->toBe(165 + 2000 - 6500 + 2345 + 999)
        ->and($march['fixed_expense'] + $march['variable_expense'] + $march['general_expense'])->toBe($march['expense']);

    // Savings: the checking legs only (the savings-account legs are skipped):
    //   split line 20000 + 25000 - withdrawal 5000 - car fund withdrawal 8000 = 32000
    // Investments: 40000 out of checking; the brokerage leg is skipped.
    expect($march['savings'])->toBe(32000)
        ->and($march['investments'])->toBe(40000);

    // Budgets in progress on 18 March, in start-date order: Food budget is over (-5000) and
    // reserves nothing; the catch-all still reserves its margin; Yearly fun is not a month budget.
    //   Housing 50000 + Holidays 20000 + Subscriptions 3701 + Dining out 5450 + Everything else 13000
    expect($march['reserved'])->toBe(50000 + 20000 + 3701 + 5450 + 13000)
        ->and(collect($march['budgets'])->firstWhere('name', 'Food budget')['margin'])->toBe(-5000);

    // Sankey crossovers: shopping nets positive in March (-3077 - 165 - 2000 + 6500 = +1258) and
    // crosses to the income side as a refund; the car fund withdrawal crosses as a withdrawal.
    expect($sankeyIncome->get('Shopping (refund)')['amount'])->toBe(1258)
        ->and($sankeyIncome->get('Car fund (withdrawal)')['amount'])->toBe(8000)
        ->and($sankeyIncome->get('Transfer in')['amount'])->toBe(30000);

    // February's gift given back, -2500 USD at 1.0805 -> -2314, nets Gifts negative: a reversal
    // on the expense side.
    expect(collect($outputs['sankey february']['expense_categories'])->keyBy('category.name')->get('Gifts (reversal)')['amount'])->toBe(2314);

    // Inside the Food drill-down, the 25000 refund nets Groceries positive
    // (25000 - 10000 - 1111 - 1459 - 330 - 2299 - 6000 = 3801): it crosses to
    // the income side under its own name, and the breakdown lists it negative.
    expect($outputs['sankey march drilled into Food']['income_categories'][0])->toMatchArray(['category_id' => 'category:Groceries', 'amount' => 3801])
        ->and(collect($outputs['breakdown march expense drilled into Food']['data'])->firstWhere('category_id', 'category:Groceries')['amount'])->toBe(-3801);

    // The trend never reaches back further than 24 months.
    expect($outputs['trend capped at the longest window']['data'])->toHaveCount(24)
        ->and($outputs['trend capped at the longest window']['data'][0]['month'])->toBe('2024-04');

    // forMonths in the household space counts both members' rows there:
    // partner salary 180000 in; joint groceries 6000 + partner groceries 4567 out.
    expect($outputs['forMonths household space']['2026-03'])->toMatchArray([
        'income' => 180000,
        'expense' => 6000 + 4567,
    ]);
});
