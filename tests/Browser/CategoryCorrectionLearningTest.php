<?php

use App\Enums\CategorySource;
use App\Enums\RuleOrigin;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('learns a forward rule from an inline correction and lets the user undo it', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Learn Bank']);
    $fuel = Category::factory()->create(['user_id' => $user->id, 'name' => 'Fuel']);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Learn Account',
        'currency_code' => 'EUR',
        'type' => 'checking',
    ]);

    // A supermarket purchase the AI mislabeled as Fuel — the exact complaint.
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $fuel->id,
        'category_source' => CategorySource::Ai,
        'ai_confidence' => 0.9,
        'creditor_name' => 'Mercadona',
        'description' => 'Mercadona compra',
        'amount' => -4300,
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->waitForText('Mercadona compra', 10)
        ->click('[data-testid="row-category-select"]')
        ->wait(1)
        ->waitForText('Groceries', 5)
        ->click('Groceries')
        ->waitForText('similar transactions will be categorized automatically', 5)
        ->assertNoJavascriptErrors();

    expect(
        AutomationRule::query()
            ->origin(RuleOrigin::Correction)
            ->where('action_category_id', $groceries->id)
            ->exists()
    )->toBeTrue();

    // Undo removes the learned rule (the correction itself stays applied).
    $page->click('Undo')->wait(2);

    expect(AutomationRule::query()->origin(RuleOrigin::Correction)->exists())->toBeFalse();
});
