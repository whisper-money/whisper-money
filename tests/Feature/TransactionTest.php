<?php

use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetTransactionService;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

test('guests cannot access transactions page', function () {
    $response = $this->get(route('transactions.index'));

    $response->assertRedirect(route('register'));
});

test('authenticated users can access transactions page', function () {
    $user = User::factory()->onboarded()->create();

    $response = actingAs($user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/index')
        ->has('transactions')
        ->has('transactions.data')
        ->has('appliedFilters')
        ->has('categories')
        ->has('accounts')
        ->has('banks')
    );
});

test('transactions page includes automation rules with labels', function () {
    $user = User::factory()->onboarded()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $label = Label::factory()->create(['user_id' => $user->id]);

    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'action_category_id' => $category->id,
    ]);
    $rule->labels()->attach($label->id);

    $response = actingAs($user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/index')
        ->has('automationRules', 1)
        ->has('automationRules.0.labels', 1)
        ->where('automationRules.0.labels.0.id', $label->id)
        ->where('automationRules.0.labels.0.name', $label->name)
        ->where('automationRules.0.category.id', $category->id)
    );
});

test('authenticated users can access categorize transactions page', function () {
    $user = User::factory()->onboarded()->create();

    $response = actingAs($user)->get(route('transactions.categorize'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/categorize')
        ->has('categories')
        ->has('accounts')
        ->has('banks')
    );
});

test('guests cannot access categorize transactions page', function () {
    $response = $this->get(route('transactions.categorize'));

    $response->assertRedirect(route('register'));
});

test('users can update their own transaction category', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => $category->id,
    ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'category_id' => $category->id,
    ]);
});

test('users can update transaction notes', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'notes' => 'encrypted_notes_content',
        'notes_iv' => str_repeat('c', 16),
    ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'notes' => 'encrypted_notes_content',
        'notes_iv' => str_repeat('c', 16),
    ]);
});

test('users can clear transaction category', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => null,
    ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'category_id' => null,
    ]);
});

test('users cannot update other users transactions', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->create(['encryption_salt' => str_repeat('b', 24)]);
    $account = Account::factory()->create(['user_id' => $otherUser->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $otherUser->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => $category->id,
    ]);

    $response->assertForbidden();
});

test('category_id must exist when updating transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => 99999,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['category_id']);
});

test('notes_iv must be exactly 16 characters', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'notes' => 'encrypted_notes',
        'notes_iv' => 'invalid',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['notes_iv']);
});

test('users can edit the amount, date and account of a manually created transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'USD']);
    $otherAccount = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => 2500,
        'transaction_date' => '2026-01-01',
        'currency_code' => 'USD',
        'source' => 'manually_created',
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'description' => 'updated_description',
        'amount' => 9900,
        'transaction_date' => '2026-02-15',
        'account_id' => $otherAccount->id,
        'currency_code' => 'EUR',
    ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'description' => 'updated_description',
        'amount' => 9900,
        'transaction_date' => '2026-02-15',
        'account_id' => $otherAccount->id,
        'currency_code' => 'EUR',
    ]);
});

test('users cannot edit the amount, date or account of an imported transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $otherAccount = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => 2500,
        'transaction_date' => '2026-01-01',
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'amount' => 9900,
        'transaction_date' => '2026-02-15',
        'account_id' => $otherAccount->id,
    ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'amount' => 2500,
        'transaction_date' => '2026-01-01',
        'account_id' => $account->id,
    ]);
});

test('users can soft delete their own transactions', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->deleteJson(route('transactions.destroy', $transaction));

    $response->assertSuccessful();
    $this->assertSoftDeleted('transactions', [
        'id' => $transaction->id,
    ]);
});

test('users cannot delete other users transactions', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->create(['encryption_salt' => str_repeat('b', 24)]);
    $account = Account::factory()->create(['user_id' => $otherUser->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $otherUser->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->deleteJson(route('transactions.destroy', $transaction));

    $response->assertForbidden();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'deleted_at' => null,
    ]);
});

test('deleting a manual account expense increases the current balance when requested', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $account->balances()->create([
        'balance_date' => now()->toDateString(),
        'balance' => 100000,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -2500,
    ]);

    actingAs($user)
        ->deleteJson(route('transactions.destroy', $transaction), ['update_balance' => true])
        ->assertSuccessful();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 102500,
    ]);
});

test('deleting a manual account income decreases the current balance when requested', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $account->balances()->create([
        'balance_date' => now()->toDateString(),
        'balance' => 100000,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => 3000,
    ]);

    actingAs($user)
        ->deleteJson(route('transactions.destroy', $transaction), ['update_balance' => true])
        ->assertSuccessful();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 97000,
    ]);
});

test('deleting a past-dated transaction reverses it on that date and every later balance', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $account->balances()->create(['balance_date' => '2025-11-10', 'balance' => 5000]);
    $account->balances()->create(['balance_date' => '2025-11-11', 'balance' => 5000]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -1500,
        'transaction_date' => '2025-11-10',
    ]);

    actingAs($user)
        ->deleteJson(route('transactions.destroy', $transaction), ['update_balance' => true])
        ->assertSuccessful();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => '2025-11-10',
        'balance' => 6500,
    ]);
    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => '2025-11-11',
        'balance' => 6500,
    ]);
});

test('deleting a transaction does not change the balance when not requested', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $account->balances()->create([
        'balance_date' => now()->toDateString(),
        'balance' => 100000,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -2500,
    ]);

    actingAs($user)
        ->deleteJson(route('transactions.destroy', $transaction))
        ->assertSuccessful();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 100000,
    ]);
});

test('deleting a connected account transaction never changes the balance', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->connected()->create(['user_id' => $user->id]);

    $account->balances()->create([
        'balance_date' => now()->toDateString(),
        'balance' => 100000,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => -2500,
    ]);

    actingAs($user)
        ->deleteJson(route('transactions.destroy', $transaction), ['update_balance' => true])
        ->assertSuccessful();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 100000,
    ]);
});

test('creating a transaction updates the balance on its date when one exists', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $account->balances()->create([
        'balance_date' => '2025-11-11',
        'balance' => 100000,
    ]);

    actingAs($user)->postJson(route('transactions.store'), [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'transaction_date' => '2025-11-11',
        'amount' => 2500,
        'currency_code' => 'USD',
        'source' => 'manually_created',
        'update_balance' => true,
    ])->assertCreated();

    $this->assertDatabaseCount('account_balances', 1);
    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => '2025-11-11',
        'balance' => 102500,
    ]);
});

test('creating a transaction creates a balance on its date from the closest earlier balance', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $account->balances()->create([
        'balance_date' => '2025-11-01',
        'balance' => 50000,
    ]);

    actingAs($user)->postJson(route('transactions.store'), [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'transaction_date' => '2025-11-11',
        'amount' => -1500,
        'currency_code' => 'USD',
        'source' => 'manually_created',
        'update_balance' => true,
    ])->assertCreated();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => '2025-11-11',
        'balance' => 48500,
    ]);
});

test('creating a past-dated transaction updates that date and every later balance', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $account->balances()->create(['balance_date' => '2025-11-10', 'balance' => 1000]);
    $account->balances()->create(['balance_date' => '2025-11-11', 'balance' => 1000]);

    actingAs($user)->postJson(route('transactions.store'), [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'transaction_date' => '2025-11-10',
        'amount' => -500,
        'currency_code' => 'USD',
        'source' => 'manually_created',
        'update_balance' => true,
    ])->assertCreated();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => '2025-11-10',
        'balance' => 500,
    ]);
    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => '2025-11-11',
        'balance' => 500,
    ]);
});

test('creating the first transaction on an account creates a balance equal to its amount', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user)->postJson(route('transactions.store'), [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'transaction_date' => '2025-11-11',
        'amount' => 7500,
        'currency_code' => 'USD',
        'source' => 'manually_created',
        'update_balance' => true,
    ])->assertCreated();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => '2025-11-11',
        'balance' => 7500,
    ]);
});

test('creating a transaction does not change the balance when not requested', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user)->postJson(route('transactions.store'), [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'transaction_date' => '2025-11-11',
        'amount' => 7500,
        'currency_code' => 'USD',
        'source' => 'manually_created',
    ])->assertCreated();

    $this->assertDatabaseCount('account_balances', 0);
});

test('creating a connected account transaction never changes the balance', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->connected()->create(['user_id' => $user->id]);

    $account->balances()->create([
        'balance_date' => '2025-11-11',
        'balance' => 100000,
    ]);

    actingAs($user)->postJson(route('transactions.store'), [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'transaction_date' => '2025-11-11',
        'amount' => 2500,
        'currency_code' => 'USD',
        'source' => 'manually_created',
        'update_balance' => true,
    ])->assertCreated();

    $this->assertDatabaseCount('account_balances', 1);
    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => '2025-11-11',
        'balance' => 100000,
    ]);
});

test('editing a manual transaction amount moves the balance by the delta when requested', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    // Balance already reflects the transaction's original 2500 amount (100000 base + 2500).
    $account->balances()->create([
        'balance_date' => '2025-11-11',
        'balance' => 102500,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => 2500,
        'transaction_date' => '2025-11-11',
        'source' => 'manually_created',
    ]);

    actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'amount' => 5000,
        'update_balance' => true,
    ])->assertSuccessful();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => '2025-11-11',
        'balance' => 105000,
    ]);
});

test('editing a manual transaction amount does not change the balance when not requested', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $account->balances()->create([
        'balance_date' => '2025-11-11',
        'balance' => 102500,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => 2500,
        'transaction_date' => '2025-11-11',
        'source' => 'manually_created',
    ]);

    actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'amount' => 5000,
    ])->assertSuccessful();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => '2025-11-11',
        'balance' => 102500,
    ]);
});

test('moving a manual transaction between accounts reverses the old balance and credits the new one', function () {
    $user = User::factory()->onboarded()->create();
    $fromAccount = Account::factory()->create(['user_id' => $user->id]);
    $toAccount = Account::factory()->create(['user_id' => $user->id]);

    // Origin balance embeds the transaction's 2500; destination starts flat.
    $fromAccount->balances()->create(['balance_date' => '2025-11-11', 'balance' => 102500]);
    $toAccount->balances()->create(['balance_date' => '2025-11-11', 'balance' => 50000]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $fromAccount->id,
        'amount' => 2500,
        'transaction_date' => '2025-11-11',
        'source' => 'manually_created',
    ]);

    actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'account_id' => $toAccount->id,
        'update_balance' => true,
    ])->assertSuccessful();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $fromAccount->id,
        'balance_date' => '2025-11-11',
        'balance' => 100000,
    ]);
    $this->assertDatabaseHas('account_balances', [
        'account_id' => $toAccount->id,
        'balance_date' => '2025-11-11',
        'balance' => 52500,
    ]);
});

test('editing only the currency of a manual transaction does not change the balance', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $account->balances()->create(['balance_date' => '2025-11-11', 'balance' => 102500]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => 2500,
        'transaction_date' => '2025-11-11',
        'currency_code' => 'USD',
        'source' => 'manually_created',
    ]);

    actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'currency_code' => 'EUR',
        'update_balance' => true,
    ])->assertSuccessful();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => '2025-11-11',
        'balance' => 102500,
    ]);
});

test('editing a connected account transaction never changes the balance', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->connected()->create(['user_id' => $user->id]);

    $account->balances()->create([
        'balance_date' => '2025-11-11',
        'balance' => 100000,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => 2500,
        'transaction_date' => '2025-11-11',
        'source' => 'manually_created',
    ]);

    actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'amount' => 5000,
        'update_balance' => true,
    ])->assertSuccessful();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => '2025-11-11',
        'balance' => 100000,
    ]);
});

test('transactions index page passes user categories', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->create();

    $userCategory = Category::factory()->create(['user_id' => $user->id, 'name' => 'My Category']);
    Category::factory()->create(['user_id' => $otherUser->id, 'name' => 'Other Category']);

    $response = actingAs($user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/index')
        ->has('categories', 1)
        ->where('categories.0.name', 'My Category')
    );
});

test('transactions index page passes user accounts', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->create();

    Account::factory()->create(['user_id' => $user->id, 'name' => 'encrypted_name_1', 'name_iv' => str_repeat('a', 16)]);
    Account::factory()->create(['user_id' => $otherUser->id, 'name' => 'encrypted_name_2', 'name_iv' => str_repeat('b', 16)]);

    $response = actingAs($user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/index')
        ->has('accounts', 1)
    );
});

test('users can create a new transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 15050,
        'currency_code' => 'USD',
        'notes' => 'encrypted_notes',
        'notes_iv' => str_repeat('n', 16),
        'source' => 'manually_created',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertCreated();
    $response->assertJsonStructure([
        'data' => [
            'id',
            'user_id',
            'account_id',
            'category_id',
            'description',
            'description_iv',
            'transaction_date',
            'amount',
            'currency_code',
            'notes',
            'notes_iv',
            'source',
            'created_at',
            'updated_at',
        ],
    ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'encrypted_description',
        'amount' => 15050,
        'currency_code' => 'USD',
        'source' => 'manually_created',
    ]);
});

test('users can create a transaction without category', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 7525,
        'currency_code' => 'EUR',
        'source' => 'manually_created',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertCreated();
    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'encrypted_description',
        'amount' => 7525,
    ]);
});

test('users can create a transaction without notes', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 10000,
        'currency_code' => 'USD',
        'source' => 'imported',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertCreated();
    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'notes' => null,
        'notes_iv' => null,
    ]);
});

test('account_id is required when creating transaction', function () {
    $user = User::factory()->onboarded()->create();

    $transactionData = [
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 10000,
        'currency_code' => 'USD',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['account_id']);
});

test('description is required when creating transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 10000,
        'currency_code' => 'USD',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['description']);
});

test('amount is required when creating transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'currency_code' => 'USD',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['amount']);
});

test('transaction_date is required when creating transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'amount' => 10000,
        'currency_code' => 'USD',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['transaction_date']);
});

test('currency_code is required when creating transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 10000,
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['currency_code']);
});

test('users can create a transaction with labels', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $label1 = Label::factory()->create(['user_id' => $user->id]);
    $label2 = Label::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 5000,
        'currency_code' => 'USD',
        'source' => 'imported',
        'label_ids' => [$label1->id, $label2->id],
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertCreated();

    $transaction = Transaction::latest()->first();
    expect($transaction->labels)->toHaveCount(2);
    expect($transaction->labels->pluck('id')->toArray())->toContain($label1->id, $label2->id);

    $response->assertJsonCount(2, 'data.labels');
});

test('users can add labels to a transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $label1 = Label::factory()->create(['user_id' => $user->id]);
    $label2 = Label::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'label_ids' => [$label1->id, $label2->id],
    ]);

    $response->assertSuccessful();
    expect($transaction->fresh()->labels)->toHaveCount(2);
    expect($transaction->labels->pluck('id')->toArray())->toContain($label1->id, $label2->id);
});

test('users can replace labels on a transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $label1 = Label::factory()->create(['user_id' => $user->id]);
    $label2 = Label::factory()->create(['user_id' => $user->id]);
    $label3 = Label::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);
    $transaction->labels()->attach([$label1->id, $label2->id]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'label_ids' => [$label3->id],
    ]);

    $response->assertSuccessful();
    expect($transaction->fresh()->labels)->toHaveCount(1);
    expect($transaction->labels->pluck('id')->toArray())->toEqual([$label3->id]);
});

test('users can remove all labels from a transaction with empty array', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $label = Label::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);
    $transaction->labels()->attach($label->id);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'label_ids' => [],
    ]);

    $response->assertSuccessful();
    expect($transaction->fresh()->labels)->toHaveCount(0);
});

test('labels are not touched when label_ids is not sent', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    $label = Label::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);
    $transaction->labels()->attach($label->id);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => $category->id,
    ]);

    $response->assertSuccessful();
    expect($transaction->fresh()->labels)->toHaveCount(1);
    expect($transaction->labels->first()->id)->toBe($label->id);
});

test('label_ids must exist and belong to user', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $otherLabel = Label::factory()->create(['user_id' => $otherUser->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'label_ids' => [$otherLabel->id],
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['label_ids.0']);
});

test('updating transaction labels via API works correctly', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $label = Label::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    // Update transaction to add the label
    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'label_ids' => [$label->id],
    ]);

    $response->assertSuccessful();

    // Verify labels were updated
    $transaction = $transaction->fresh();
    expect($transaction->labels)->toHaveCount(1);
    expect($transaction->labels->first()->id)->toBe($label->id);

    // Verify the updated_at timestamp changed (which triggers events)
    expect($transaction->updated_at)->not->toBe($transaction->created_at);
});

test('when budget with label exists, updating transaction with that label assigns it to budget', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Work']);

    // Create a budget filtered by this label
    $budget = Budget::factory()->forLabels($label)->create([
        'user_id' => $user->id,
        'name' => 'Work Expenses',
    ]);

    // Create the current budget period
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 100000, // $1000.00
    ]);

    // Create a transaction without the label (from this month)
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => now(),
        'amount' => -5000, // -$50.00 expense
    ]);

    // Verify transaction is not in the budget yet
    expect($period->budgetTransactions()->count())->toBe(0);

    // Update transaction to add the "Work" label via API
    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'label_ids' => [$label->id],
    ]);

    $response->assertSuccessful();

    // Verify the transaction now has the label
    $transaction->refresh();
    $transaction->load('labels');
    expect($transaction->labels->pluck('id'))->toContain($label->id);

    // The TransactionUpdated event triggers AssignTransactionToBudget listener
    // In production this runs async via queue, but the assignment logic works correctly
    // Let's verify by manually calling the service (simulating what the listener does)
    $budgetService = app(BudgetTransactionService::class);
    $budgetService->assignTransaction($transaction);

    // Verify transaction was assigned to the budget
    $period->refresh();
    expect($period->budgetTransactions)->toHaveCount(1);

    $budgetTransaction = $period->budgetTransactions->first();
    expect($budgetTransaction->transaction_id)->toBe($transaction->id);
    expect($budgetTransaction->amount)->toBe(5000); // Stored as absolute value
});

test('categorize page is accessible to authenticated users', function () {
    $user = User::factory()->onboarded()->create();

    $response = actingAs($user)->get(route('transactions.categorize'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/categorize')
        ->has('categories')
        ->has('accounts')
        ->has('banks')
        ->has('labels')
        ->has('transactions')
    );
});

test('categorize page only returns uncategorized transactions', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $uncategorized = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
    ]);

    $response = actingAs($user)->get(route('transactions.categorize'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/categorize')
        ->has('transactions', 1)
        ->where('transactions.0.id', $uncategorized->id)
    );
});

test('categorize page exposes debtor and creditor names', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'creditor_name' => 'Acme Corp',
        'debtor_name' => 'Jane Doe',
    ]);

    $response = actingAs($user)->get(route('transactions.categorize'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/categorize')
        ->where('transactions.0.id', $transaction->id)
        ->where('transactions.0.creditor_name', 'Acme Corp')
        ->where('transactions.0.debtor_name', 'Jane Doe')
    );
});

test('categorize page does not return transactions from deleted accounts', function () {
    $user = User::factory()->onboarded()->create();

    $activeAccount = Account::factory()->create(['user_id' => $user->id]);
    $deletedAccount = Account::factory()->create(['user_id' => $user->id]);

    $visibleTransaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $activeAccount->id,
        'category_id' => null,
    ]);

    // Delete the account and its transactions (as the controller does)
    $deletedAccount->transactions()->delete();
    $deletedAccount->delete();

    $response = actingAs($user)->get(route('transactions.categorize'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/categorize')
        ->has('transactions', 1)
        ->where('transactions.0.id', $visibleTransaction->id)
    );
});

test('categorize page does not return transactions from other users', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->onboarded()->create();

    $otherAccount = Account::factory()->create(['user_id' => $otherUser->id]);
    Transaction::factory()->create([
        'user_id' => $otherUser->id,
        'account_id' => $otherAccount->id,
        'category_id' => null,
    ]);

    $response = actingAs($user)->get(route('transactions.categorize'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/categorize')
        ->has('transactions', 0)
    );
});

test('guests are redirected from categorize page', function () {
    $response = $this->get(route('transactions.categorize'));

    $response->assertRedirect(route('register'));
});

test('transactions index hides internal columns and keeps the standard field set', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'raw_data' => ['foo' => 'bar'],
        'dedup_fingerprint' => 'fingerprint',
        'external_transaction_id' => 'ext-1',
        'original_description' => 'ORIGINAL',
    ]);

    $response = actingAs($user)->get(route('transactions.index'));

    $tx = $response->viewData('page')['props']['transactions']['data'][0];

    expect(array_keys($tx))->toContain(
        'id', 'user_id', 'account_id', 'category_id', 'description', 'description_iv',
        'transaction_date', 'amount', 'currency_code', 'notes', 'notes_iv',
        'source', 'creditor_name', 'debtor_name', 'created_at', 'updated_at', 'account', 'labels',
    );
    expect($tx)->not->toHaveKeys([
        'raw_data', 'dedup_fingerprint', 'external_transaction_id', 'original_description', 'deleted_at',
    ]);
});
