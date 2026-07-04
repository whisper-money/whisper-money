<?php

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
});

function makeSideTransaction(User $user, Account $account, ?Category $category, int $amount): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'amount' => $amount,
    ])->load('category');
}

it('classifies an income-category transaction as income side regardless of sign', function () {
    $income = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Income]);

    $positive = makeSideTransaction($this->user, $this->account, $income, 10000);
    $reversal = makeSideTransaction($this->user, $this->account, $income, -2000);

    expect($positive->isIncomeSide())->toBeTrue()
        ->and($positive->isExpenseSide())->toBeFalse()
        ->and($reversal->isIncomeSide())->toBeTrue();
});

it('classifies an expense-category transaction as expense side regardless of sign', function () {
    $expense = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    $spend = makeSideTransaction($this->user, $this->account, $expense, -5000);
    $refund = makeSideTransaction($this->user, $this->account, $expense, 1500);

    expect($spend->isExpenseSide())->toBeTrue()
        ->and($spend->isIncomeSide())->toBeFalse()
        ->and($refund->isExpenseSide())->toBeTrue();
});

it('classifies uncategorized inflows as income and outflows as expense', function () {
    $inflow = makeSideTransaction($this->user, $this->account, null, 7000);
    $outflow = makeSideTransaction($this->user, $this->account, null, -3000);

    expect($inflow->isIncomeSide())->toBeTrue()
        ->and($inflow->isExpenseSide())->toBeFalse()
        ->and($outflow->isExpenseSide())->toBeTrue()
        ->and($outflow->isIncomeSide())->toBeFalse();
});

it('treats transfer, savings and investment categories as neither side', function () {
    foreach ([CategoryType::Transfer, CategoryType::Savings, CategoryType::Investment] as $type) {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => $type]);
        $transaction = makeSideTransaction($this->user, $this->account, $category, -5000);

        expect($transaction->isIncomeSide())->toBeFalse()
            ->and($transaction->isExpenseSide())->toBeFalse();
    }
});
