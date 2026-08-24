<?php

use App\Enums\AccountType;
use App\Enums\PlanFeature;
use App\Models\User;
use App\Services\Demo\PressDataset;

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
        ->and($user->name)->toBe(PressDataset::NAME)
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
