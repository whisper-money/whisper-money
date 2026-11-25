<?php

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\AnalyticsPinWorld;
use Tests\Support\CashflowEquivalenceWorld;

/*
 * The two invented households whose figures are pinned in golden files, run
 * through the conversion of their legacy splits: every pinned figure must
 * come out the same once their lines are parts. The golden files are only
 * read here.
 */

beforeEach(function () {
    Http::fake();
    $this->travelTo(Carbon::parse('2026-03-18 12:00:00'));
});

it('leaves every cashflow figure of the equivalence household as pinned once its splits are parts', function () {
    $world = CashflowEquivalenceWorld::build();

    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])
        ->expectsOutputToContain('Legacy splits to convert: 2')
        ->expectsOutputToContain('Skipped: 4')
        ->expectsOutputToContain('flagged by transactions:audit-splits (category_type_invalid_or_mixed): 1')
        ->expectsOutputToContain('flagged by transactions:audit-splits (category_type_invalid_or_mixed, split_sign_mismatch): 1')
        ->expectsOutputToContain('flagged by transactions:audit-splits (parent_classification_present, split_sum_mismatch): 1')
        ->expectsOutputToContain('flagged by transactions:audit-splits (split_sum_mismatch): 1')
        ->expectsOutputToContain('Verification passed')
        ->assertSuccessful();

    // February's food split and the GBP one at 50% are parts now. The four
    // the audit flags stay as they were: the two whose lines leave a residue
    // to the parent, the rent split with a sublet income line and the
    // groceries split with a savings line.
    expect(Transaction::onlyTrashed()->whereHas('splitParts')->count())->toBe(2)
        ->and(Transaction::query()->whereHas('splits')->count())->toBe(4);

    foreach (Transaction::query()->whereNotNull('split_parent_id')->orderBy('amount')->get() as $position => $part) {
        $world['names'][$part->id] = $world['names'][$part->split_parent_id]." part {$position}";
    }

    $outputs = CashflowEquivalenceWorld::outputs($world);
    $golden = json_decode((string) file_get_contents(CashflowEquivalenceWorld::GOLDEN), true, flags: JSON_THROW_ON_ERROR);

    // The one thing that moves: a category's place in a list kept in
    // first-seen (date, id) order. February's food split was seen before the
    // fun row booked the same day; its parts have new ids, which sort after
    // every id of that day. Amounts and every other figure are unchanged.
    $february = 'sankey february';
    expect(array_column($outputs[$february]['expense_categories'], 'category_id'))
        ->toBe(['category:Home', 'category:Fun', 'category:Food', 'category:Gifts', null])
        ->and(array_column($golden[$february]['expense_categories'], 'category_id'))
        ->toBe(['category:Home', 'category:Food', 'category:Fun', 'category:Gifts', null]);

    $byCategory = fn (array $rows): array => collect($rows)->sortBy('category_id')->values()->all();
    $outputs[$february]['expense_categories'] = $byCategory($outputs[$february]['expense_categories']);
    $golden[$february]['expense_categories'] = $byCategory($golden[$february]['expense_categories']);

    expect(array_keys($outputs))->toBe(array_keys($golden));

    foreach ($golden as $call => $expected) {
        expect($outputs[$call])->toBe($expected, "The output of {$call} changed.");
    }
});

it('answers every pinned analytics call byte for byte once the household\'s splits went through the conversion', function () {
    $world = AnalyticsPinWorld::build();

    // Every split of this household is one the audit flags (lines of mixed
    // category types, a line moving money the other way, a residue left to a
    // categorized parent), so the conversion leaves all three alone.
    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])
        ->expectsOutputToContain('Legacy splits to convert: 0')
        ->expectsOutputToContain('Skipped: 3')
        ->expectsOutputToContain('Nothing to do.')
        ->assertSuccessful();

    expect(Transaction::onlyTrashed()->whereHas('splitParts')->count())->toBe(0)
        ->and(Transaction::query()->whereHas('splits')->count())->toBe(3);

    $outputs = AnalyticsPinWorld::outputs($world);
    $golden = json_decode((string) file_get_contents(AnalyticsPinWorld::GOLDEN), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($outputs))->toBe(array_keys($golden));

    foreach ($outputs as $call => $bytes) {
        expect($bytes)->toBe($golden[$call], "'{$call}' changed");
    }
});
