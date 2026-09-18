<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AuthBrowserFlow;

use function Pest\Laravel\actingAs;

/**
 * The three things a reader can do to their own account from settings: change
 * the password, delete the account, and change the currency their money is
 * written in. The first two ask for the password and have to refuse a wrong one;
 * the third has to reach the screens that are not the settings page.
 */
beforeEach(function () {
    // None of these are about billing, and an active subscription would block
    // the deletion for reasons of its own.
    config(['subscriptions.enabled' => false]);
});

it('changes the password and signs in with the new one', function () {
    $user = AuthBrowserFlow::user();

    AuthBrowserFlow::signInAndOpenDashboard($user);

    $page = visit('/settings/password');

    // Knowing the current password is what stops a borrowed session from locking
    // the owner out of their own account.
    $page->assertSee('Update password')
        ->fill('current_password', 'not-the-current-password')
        ->fill('password', 'a-longer-secret-2026')
        ->fill('password_confirmation', 'a-longer-secret-2026')
        ->click('@update-password-button')
        ->assertSee('The password is incorrect')
        ->assertNoJavascriptErrors();

    expect(Hash::check(AuthBrowserFlow::PASSWORD, $user->refresh()->password))->toBeTrue();

    $page->fill('current_password', AuthBrowserFlow::PASSWORD)
        ->fill('password', 'a-longer-secret-2026')
        ->fill('password_confirmation', 'a-longer-secret-2026')
        ->click('@update-password-button')
        ->assertSee('Saved');

    expect(Hash::check('a-longer-secret-2026', $user->refresh()->password))->toBeTrue();

    AuthBrowserFlow::signOut($page);

    AuthBrowserFlow::signInAndOpenDashboard($user, 'a-longer-secret-2026')
        ->assertPathIs('/dashboard')
        ->assertNoJavascriptErrors();
});

it('deletes the account once the password confirms it', function () {
    $user = AuthBrowserFlow::user();

    AuthBrowserFlow::signInAndOpenDashboard($user);

    $page = visit('/settings/delete-account');

    $page->assertSee('Please proceed with caution, this cannot be undone')
        ->click('@delete-user-button')
        ->assertSee('Are you sure you want to delete your account?')
        ->fill('password', 'not-the-current-password')
        ->click('@confirm-delete-user-button')
        ->assertSee('The password is incorrect')
        ->assertNoJavascriptErrors();

    expect(User::query()->find($user->id))->not->toBeNull();

    $page->fill('password', AuthBrowserFlow::PASSWORD)
        ->click('@confirm-delete-user-button')
        ->assertSee('Whisper Money')
        ->assertPathIs('/')
        ->assertNoJavascriptErrors();

    expect(User::query()->find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id)?->deleted_at)->not->toBeNull();
});

it('writes amounts in the currency and region the profile picks', function () {
    // Every rate is 1:1, so the only thing that can move the figure below is the
    // currency and region the profile is set to.
    fakeCurrencyApi();

    $user = AuthBrowserFlow::user();

    $account = Account::factory()->create([
        'user_id' => $user->id,
        'name' => 'Everyday Account',
        'currency_code' => 'USD',
        'type' => 'checking',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonth()->startOfMonth()->toDateString(),
        'balance' => 123456,
    ]);

    actingAs($user);

    $page = visit('/accounts');

    $page->assertSee('Everyday Account')
        ->assertSee('$1,234.56')
        ->assertNoJavascriptErrors();

    $page = visit('/settings/account');

    $page->assertSee('Profile information')
        ->click('[data-testid="currency-code-select"]')
        ->click('[role="option"]:has-text("EUR - Euro")')
        ->click('[data-testid="format-locale-select"]')
        ->click('[role="option"]:has-text("Spain")')
        ->click('@update-profile-button')
        ->assertSee('Saved')
        ->assertNoJavascriptErrors();

    expect($user->refresh()->currency_code)->toBe('EUR')
        ->and($user->format_locale)->toBe('es-ES');

    $page = visit('/accounts');

    // Spain writes the symbol last and swaps the separators, so this one string
    // carries both halves of what was just saved.
    $page->assertSee('Everyday Account')
        ->assertSee('1.234,56 €')
        ->assertNoJavascriptErrors();
});
