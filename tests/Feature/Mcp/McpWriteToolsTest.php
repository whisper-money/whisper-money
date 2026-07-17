<?php

use App\Enums\CategorySource;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CategorizeTransaction;
use App\Mcp\Tools\CreateAutomationRule;
use App\Mcp\Tools\CreateBalance;
use App\Mcp\Tools\CreateCategory;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateTransaction;
use App\Mcp\Tools\DeleteAutomationRule;
use App\Mcp\Tools\DeleteCategory;
use App\Mcp\Tools\DeleteLabel;
use App\Mcp\Tools\DeleteTransaction;
use App\Mcp\Tools\LabelTransaction;
use App\Mcp\Tools\UpdateAutomationRule;
use App\Mcp\Tools\UpdateCategory;
use App\Mcp\Tools\UpdateLabel;
use App\Mcp\Tools\UpdateTransaction;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * Call a write tool as $user, giving them a real personal access token with the
 * given abilities so the tool's tokenCan('mcp:write') gate is exercised exactly
 * as it is over HTTP.
 *
 * @param  array<string, mixed>  $arguments
 * @param  list<string>  $abilities
 */
function callWriteTool(User $user, string $tool, array $arguments = [], array $abilities = ['mcp:read', 'mcp:write']): TestResponse
{
    $user->withAccessToken($user->createToken('mcp', $abilities)->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

it('creates a manual transaction and defaults the currency to the account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

    callWriteTool($user, CreateTransaction::class, [
        'account_id' => $account->id,
        'description' => 'Blue Bottle Coffee',
        'amount' => -450,
        'transaction_date' => '2026-01-15',
    ])->assertOk()->assertSee('Blue Bottle Coffee');

    $transaction = Transaction::query()->where('account_id', $account->id)->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->description)->toBe('Blue Bottle Coffee');
    expect($transaction->currency_code)->toBe('EUR');
    expect($transaction->source->value)->toBe('manually_created');
});

it('refuses to create a transaction on a connected account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->connected()->create(['user_id' => $user->id]);

    callWriteTool($user, CreateTransaction::class, [
        'account_id' => $account->id,
        'description' => 'Nope',
        'amount' => -100,
        'transaction_date' => '2026-01-15',
    ])->assertHasErrors(['connected']);

    expect(Transaction::query()->where('account_id', $account->id)->count())->toBe(0);
});

it('edits a manual transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Old description',
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'description' => 'Fresh description',
    ])->assertOk()->assertSee('Fresh description');

    expect($transaction->fresh()->description)->toBe('Fresh description');
});

it('refuses to edit an imported transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'description' => 'Hacked',
    ])->assertHasErrors(['manually-created']);
});

it('deletes a manual transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    callWriteTool($user, DeleteTransaction::class, [
        'transaction_id' => $transaction->id,
    ])->assertOk();

    expect(Transaction::query()->find($transaction->id))->toBeNull();
});

it('refuses to delete an imported transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    callWriteTool($user, DeleteTransaction::class, [
        'transaction_id' => $transaction->id,
    ])->assertHasErrors(['manually-created']);

    expect(Transaction::query()->find($transaction->id))->not->toBeNull();
});

it('categorizes an imported transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Dining']);

    callWriteTool($user, CategorizeTransaction::class, [
        'transaction_id' => $transaction->id,
        'category_id' => $category->id,
    ])->assertOk();

    $transaction->refresh();

    expect($transaction->category_id)->toBe($category->id);
    expect($transaction->category_source)->toBe(CategorySource::Manual);
});

it('adds a label to an imported transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);
    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Reimbursable']);

    callWriteTool($user, LabelTransaction::class, [
        'transaction_id' => $transaction->id,
        'add_label_ids' => [$label->id],
    ])->assertOk()->assertSee('Reimbursable');

    expect($transaction->fresh()->labels->pluck('id')->all())->toContain($label->id);
});

it('records a balance on a manual account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    callWriteTool($user, CreateBalance::class, [
        'account_id' => $account->id,
        'balance' => 250000,
        'balance_date' => '2026-01-31',
    ])->assertOk();

    expect($account->balances()->whereDate('balance_date', '2026-01-31')->value('balance'))->toBe(250000);
});

it('refuses to record a balance on a connected account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->connected()->create(['user_id' => $user->id]);

    callWriteTool($user, CreateBalance::class, [
        'account_id' => $account->id,
        'balance' => 250000,
    ])->assertHasErrors(['connected']);

    expect($account->balances()->count())->toBe(0);
});

it('creates, updates and deletes a category', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateCategory::class, [
        'name' => 'Travel',
        'icon' => 'Plane',
        'color' => 'blue',
        'type' => 'expense',
    ])->assertOk()->assertSee('Travel');

    $category = $user->categories()->where('name', 'Travel')->firstOrFail();

    callWriteTool($user, UpdateCategory::class, [
        'category_id' => $category->id,
        'name' => 'Holidays',
    ])->assertOk()->assertSee('Holidays');

    expect($category->fresh()->name)->toBe('Holidays');

    callWriteTool($user, DeleteCategory::class, [
        'category_id' => $category->id,
    ])->assertOk();

    expect(Category::query()->find($category->id))->toBeNull();
});

it('creates, updates and deletes a label', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateLabel::class, [
        'name' => 'Business',
        'color' => 'green',
    ])->assertOk()->assertSee('Business');

    $label = $user->labels()->where('name', 'Business')->firstOrFail();

    callWriteTool($user, UpdateLabel::class, [
        'label_id' => $label->id,
        'name' => 'Work',
    ])->assertOk()->assertSee('Work');

    expect($label->fresh()->name)->toBe('Work');

    callWriteTool($user, DeleteLabel::class, [
        'label_id' => $label->id,
    ])->assertOk();

    expect(Label::query()->find($label->id))->toBeNull();
});

it('creates, updates and deletes an automation rule', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);

    callWriteTool($user, CreateAutomationRule::class, [
        'title' => 'Grocery rule',
        'priority' => 0,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $category->id,
    ])->assertOk()->assertSee('Grocery rule');

    $rule = $user->automationRules()->where('title', 'Grocery rule')->firstOrFail();

    expect($rule->action_category_id)->toBe($category->id);

    callWriteTool($user, UpdateAutomationRule::class, [
        'automation_rule_id' => $rule->id,
        'title' => 'Supermarket rule',
    ])->assertOk()->assertSee('Supermarket rule');

    expect($rule->fresh()->title)->toBe('Supermarket rule');

    callWriteTool($user, DeleteAutomationRule::class, [
        'automation_rule_id' => $rule->id,
    ])->assertOk();

    expect(AutomationRule::query()->find($rule->id))->toBeNull();
});

it('requires an automation rule to have at least one action', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateAutomationRule::class, [
        'title' => 'No action',
        'priority' => 0,
        'rules_json' => ['==' => [1, 1]],
    ])->assertHasErrors(['action']);

    expect($user->automationRules()->count())->toBe(0);
});

it('rejects a write tool called with a read-only token', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateLabel::class, [
        'name' => 'Should not exist',
        'color' => 'blue',
    ], ['mcp:read'])->assertHasErrors(['read-only']);

    expect($user->labels()->count())->toBe(0);
});

it('still enforces the Pro-plan gate on write tools', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();

    callWriteTool($user, CreateLabel::class, [
        'name' => 'Gated',
        'color' => 'blue',
    ])->assertHasErrors(['Pro']);

    expect($user->labels()->count())->toBe(0);
});

it('never lets a write tool touch another user\'s data', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    $otherTransaction = Transaction::factory()->create([
        'user_id' => $other->id,
        'account_id' => $otherAccount->id,
    ]);

    callWriteTool($user, DeleteTransaction::class, [
        'transaction_id' => $otherTransaction->id,
    ])->assertHasErrors();

    expect(Transaction::query()->find($otherTransaction->id))->not->toBeNull();
});
