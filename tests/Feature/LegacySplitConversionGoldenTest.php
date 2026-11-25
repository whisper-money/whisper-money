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
        ->expectsOutputToContain('Legacy splits to convert: 3')
        ->expectsOutputToContain('Skipped: 3')
        ->expectsOutputToContain('flagged by transactions:audit-splits (split_sign_mismatch): 1')
        ->expectsOutputToContain('flagged by transactions:audit-splits (split_sum_mismatch): 2')
        ->expectsOutputToContain('Verification passed')
        ->assertSuccessful();

    // February's food split, the GBP one at 50% and the groceries split with
    // a savings line (lines of mixed category types, which parts do not mind)
    // are parts now. The three left alone: the two whose lines leave a
    // residue to the parent, and the rent split with a sublet income line
    // moving money the other way.
    $names = fn ($transactions): array => $transactions->map(fn (Transaction $transaction): string => $world['names'][$transaction->id])->sort()->values()->all();

    expect($names(Transaction::onlyTrashed()->whereHas('splitParts')->get()))
        ->toBe(['transaction:Feb food split', 'transaction:Mar GBP 50% split', 'transaction:Mar groceries and savings split'])
        ->and($names(Transaction::query()->whereHas('splits')->get()))
        ->toBe(['transaction:Mar 33% split', 'transaction:Mar USD split with residue', 'transaction:Mar rent and sublet split']);

    foreach (Transaction::query()->whereNotNull('split_parent_id')->orderBy('amount')->get() as $position => $part) {
        $world['names'][$part->id] = $world['names'][$part->split_parent_id]." part {$position}";
    }

    $outputs = CashflowEquivalenceWorld::outputs($world);
    $golden = json_decode((string) file_get_contents(CashflowEquivalenceWorld::GOLDEN), true, flags: JSON_THROW_ON_ERROR);

    // The one thing that moves: a category's place in a list kept in
    // first-seen (date, id) order. February's food split was seen before the
    // fun row booked the same day; its parts have new ids, which sort after
    // every id of that day. The groceries and savings split is the only row
    // of its day, so its parts move nothing. Amounts and every other figure
    // are unchanged.
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

    // Every split of this household is one the audit flags. The groceries and
    // savings one (lines of mixed category types) is converted: parts do not
    // mind. The rent split with a salary line moving money the other way and
    // the USD one whose lines leave a residue to its categorized parent stay
    // as they were.
    $this->artisan('transactions:convert-legacy-splits', ['--execute' => true])
        ->expectsOutputToContain('Legacy splits to convert: 1')
        ->expectsOutputToContain('Skipped: 2')
        ->expectsOutputToContain('flagged by transactions:audit-splits (split_sign_mismatch): 1')
        ->expectsOutputToContain('flagged by transactions:audit-splits (split_sum_mismatch): 1')
        ->expectsOutputToContain('Verification passed')
        ->assertSuccessful();

    $converted = Transaction::onlyTrashed()->whereHas('splitParts')->get();

    expect($converted->map(fn (Transaction $original): array => [$original->transaction_date->toDateString(), $original->amount])->all())
        ->toBe([['2026-03-03', -30000]])
        ->and(Transaction::query()->where('split_parent_id', $converted->first()->id)->orderBy('amount')->pluck('amount')->all())->toBe([-20000, -10000])
        ->and(Transaction::query()->whereHas('splits')->orderBy('transaction_date')->pluck('amount')->all())->toBe([-40000, -10001]);

    // Byte for byte: the converted split is the only row of its day, so no
    // first-seen order moves, and it is in euros, so no FX rounding either.

    $outputs = AnalyticsPinWorld::outputs($world);
    $golden = json_decode((string) file_get_contents(AnalyticsPinWorld::GOLDEN), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($outputs))->toBe(array_keys($golden));

    foreach ($outputs as $call => $bytes) {
        expect($bytes)->toBe($golden[$call], "'{$call}' changed");
    }
});
