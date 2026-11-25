<?php

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

/*
 * The old split lines (`transaction_splits`) are only read now: a split is
 * made of parts (TransactionSplitter, tested in SplitTransactionTest). These
 * pin how a row still carrying lines reads, and what its lines still guard,
 * until it is converted.
 */

uses(RefreshDatabase::class);

function splitCategory(Transaction $transaction, CategoryType $type = CategoryType::Expense): Category
{
    return Category::factory()->create([
        'user_id' => $transaction->user_id,
        'space_id' => $transaction->space_id,
        'type' => $type,
    ]);
}

it('persists ordered splits and cascades them with the parent', function () {
    $transaction = Transaction::factory()->create(['amount' => -10000]);
    $first = splitCategory($transaction);
    $second = splitCategory($transaction);

    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $second->id, 'amount' => -4000, 'position' => 1]);
    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $first->id, 'amount' => -6000, 'position' => 0]);

    expect($transaction->splits()->pluck('amount')->all())->toBe([-6000, -4000])
        ->and($transaction->splits->first()->amount)->toBeInt();

    Transaction::withoutEvents(fn () => $transaction->forceDelete());
    expect(TransactionSplit::query()->where('transaction_id', $transaction->id)->count())->toBe(0);
});

it('reads a row filed under lines as split, with no category of its own', function () {
    $transaction = Transaction::factory()->create(['amount' => -10000, 'category_source' => 'ai', 'ai_confidence' => .9]);

    fileUnderLegacyLines($transaction, [
        ['category_id' => splitCategory($transaction)->id, 'amount' => -6000],
        ['category_id' => splitCategory($transaction)->id, 'amount' => -4000],
    ]);

    $transaction->refresh()->load('splits.category');

    expect($transaction->category_id)->toBeNull()
        ->and($transaction->category_source)->toBeNull()
        ->and($transaction->splits)->toHaveCount(2)
        ->and($transaction->is_split)->toBeTrue()
        ->and($transaction->split_count)->toBe(2)
        ->and($transaction->isSplitPart())->toBeFalse();
});

it('protects split categories from deletion and type changes', function () {
    $transaction = Transaction::factory()->create(['amount' => -10000]);
    $first = splitCategory($transaction);
    $second = splitCategory($transaction);
    fileUnderLegacyLines($transaction, [
        ['category_id' => $first->id, 'amount' => -6000],
        ['category_id' => $second->id, 'amount' => -4000],
    ]);

    expect(fn () => $first->delete())->toThrow(ValidationException::class)
        ->and(fn () => $first->update(['type' => CategoryType::Income]))->toThrow(ValidationException::class);

    expect($first->fresh())->not->toBeNull()
        ->and($first->fresh()->type)->toBe(CategoryType::Expense)
        ->and($transaction->splits()->count())->toBe(2);
});
