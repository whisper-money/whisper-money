<?php

use App\Enums\AccountType;
use App\Enums\PlanFeature;
use App\Models\User;
use App\Services\AutomationRuleApplier;

beforeEach(function () {
    config(['app.press' => [
        'email' => 'prensa@whisper.money',
        'password' => '123456789',
    ]]);
});

test('demo:reset --press fails when the press account is not configured', function () {
    config(['app.press.email' => null]);

    $this->artisan('demo:reset --press')->assertFailed();

    expect(User::where('email', 'prensa@whisper.money')->exists())->toBeFalse();
});

test('demo:reset --press ignores DEMO_ENABLED', function () {
    config(['app.demo.enabled' => false, 'app.press.email' => null]);

    // Failing on the missing press config (rather than reporting the demo as
    // disabled) is what proves the demo switch is not consulted.
    $this->artisan('demo:reset --press')->assertFailed();
});

test('demo:reset --press seeds a Spanish account that survives a re-run', function () {
    config(['subscriptions.enabled' => true]);

    $this->artisan('demo:reset --press')->assertSuccessful();

    $user = User::where('email', 'prensa@whisper.money')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Marta Ruiz Ferrer')
        ->and($user->locale)->toBe('es')
        ->and($user->currency_code)->toBe('EUR')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->onboarded_at)->not->toBeNull();

    // The whole point of the account: a working MCP.
    expect($user->canUseFeature(PlanFeature::McpAccess))->toBeTrue()
        ->and($user->subscription('default')->stripe_id)->toStartWith('sub_press_')
        ->and($user->hasSeededSubscription())->toBeTrue();

    // Spanish categories, because the locale drives CreateDefaultCategories.
    expect($user->categories()->where('name', 'Supermercado')->exists())->toBeTrue();

    // Spanish merchants, in euros, ending today so the current month is not empty.
    expect($user->transactions()->count())->toBeGreaterThan(800)
        ->and($user->transactions()->where('description', 'Mercadona')->exists())->toBeTrue()
        ->and($user->transactions()->where('currency_code', 'EUR')->count())
        ->toBe($user->transactions()->count())
        ->and($user->transactions()->whereDate('transaction_date', '>=', now()->startOfMonth())->exists())
        ->toBeTrue();

    // Net worth needs a liability and an asset that is not a bank account.
    $types = $user->accounts()->pluck('type');
    expect($types)->toContain(AccountType::Loan)
        ->and($types)->toContain(AccountType::RealEstate)
        ->and($types)->toContain(AccountType::CreditCard);

    $mortgage = $user->accounts()->where('type', AccountType::Loan)->first();
    $flat = $user->accounts()->where('type', AccountType::RealEstate)->first();

    expect($mortgage->loanDetail)->not->toBeNull()
        ->and($mortgage->balances()->count())->toBeGreaterThan(0)
        ->and($flat->realEstateDetail)->not->toBeNull()
        ->and($flat->balances()->count())->toBeGreaterThan(0);

    // Budgets have to be tracking something, or the budget questions come back
    // empty (the category lives in a pivot, not on a column).
    $budget = $user->budgets()->where('name', 'Compra del mes')->first();
    expect($budget)->not->toBeNull()
        ->and($budget->categories()->count())->toBe(1)
        ->and($budget->getCurrentPeriod())->not->toBeNull();

    expect($budget->periods()->withCount('budgetTransactions')->get()->sum('budget_transactions_count'))
        ->toBeGreaterThan(0);

    // No automated e-mail goes to an inbox nobody reads.
    expect($user->setting->notify_on_inactive_no_bank)->toBeFalse()
        ->and($user->setting->notify_on_bank_transactions_synced)->toBeFalse()
        ->and($budget->notify_on_over_limit)->toBeFalse();

    // A reseed must not recreate the row: an OAuth connection and every MCP
    // token hang off the user id, and recreating it breaks them silently.
    $token = $user->createToken('claude', ['mcp:read', 'mcp:write']);

    $this->artisan('demo:reset --press')->assertSuccessful();

    $reseeded = User::where('email', 'prensa@whisper.money')->first();

    expect($reseeded->id)->toBe($user->id)
        ->and($reseeded->tokens()->whereKey($token->accessToken->id)->exists())->toBeTrue()
        ->and($reseeded->subscriptions()->count())->toBe(1);
})->group('slow');

test('demo:reset --press seeds a history that automation rules can actually match', function () {
    $this->artisan('demo:reset --press')->assertSuccessful();

    $user = User::where('email', 'prensa@whisper.money')->first();
    $savings = $user->accounts()->where('type', AccountType::Savings)->first();

    // Server-side rule evaluation blanks account_name for encrypted accounts,
    // so a seeded account left on the column default (true) with a plaintext
    // name can never be matched by a rule keyed on the account it belongs to.
    expect($user->accounts()->where('encrypted', true)->count())->toBe(0)
        ->and($savings->transactions()->count())->toBeGreaterThan(0);

    $label = $user->labels()->create(['name' => 'Objetivo', 'color' => '#10b981']);
    $rule = $user->automationRules()->create([
        'title' => 'Todo el ahorro',
        'priority' => 0,
        'rules_json' => ['==' => [['var' => 'account_name'], $savings->name]],
    ]);
    $rule->labels()->sync([$label->id]);

    $matches = app(AutomationRuleApplier::class)->matchingIds($rule->fresh(), false);

    expect($matches)->toHaveCount($savings->transactions()->count());

    // No seeded bank may point at a third-party CDN that can go dark.
    expect($user->accounts()->with('bank')->get()->pluck('bank.logo')->filter())
        ->each->toStartWith('/images/banks/logos/');
})->group('slow');

test('demo:reset --press leaves no orphaned savings goal behind', function () {
    $this->artisan('demo:reset --press')->assertSuccessful();

    $user = User::where('email', 'prensa@whisper.money')->first();
    $label = $user->labels()->create(['name' => 'Moto', 'color' => '#10b981']);
    $user->savingsGoals()->create([
        'label_id' => $label->id,
        'name' => 'Moto',
        'target_amount' => 1700000,
        'initial_amount' => 0,
    ]);

    // Deleting the label only nulls label_id, so a goal that survives the
    // reseed lingers as an empty shell on the planning screen.
    $this->artisan('demo:reset --press')->assertSuccessful();

    expect($user->savingsGoals()->withTrashed()->count())->toBe(0);
})->group('slow');
