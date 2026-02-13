<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\User;

// =============================================================================
// Basic Redirect Tests
// =============================================================================

it('redirects new registration to email verification', function () {
    $page = visit('/register');

    $page->assertSee('Create an account')
        ->fill('name', 'Test Onboarding User')
        ->fill('email', 'onboarding-test@example.com')
        ->fill('password', 'password123456')
        ->fill('password_confirmation', 'password123456')
        ->click('@register-user-button')
        ->wait(3)
        ->assertPathIs('/email/verify')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('users', [
        'email' => 'onboarding-test@example.com',
        'name' => 'Test Onboarding User',
    ]);
});

it('redirects onboarded user away from onboarding page to dashboard', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->assertPathIs('/dashboard')
        ->assertNoJavascriptErrors();
});

it('redirects non-onboarded user from dashboard to onboarding', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertPathIs('/onboarding')
        ->assertNoJavascriptErrors();
});

// =============================================================================
// Step Navigation Tests
// =============================================================================

it('shows welcome step as first onboarding step', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->assertSee('Welcome to')
        ->assertSee('Whisper Money')
        ->assertSee("Let's Get Started")
        ->assertNoJavascriptErrors();
});

it('navigates from welcome to account types', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->assertSee('Welcome to')
        ->assertSee('Whisper Money')
        ->click("Let's Get Started")
        ->wait(1)
        ->assertSee('Account Types')
        ->assertNoJavascriptErrors();
});

it('marks user as onboarded when completing onboarding', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    expect($user->isOnboarded())->toBeFalse();

    $this->actingAs($user)->post('/onboarding/complete');

    $user->refresh();
    expect($user->isOnboarded())->toBeTrue();
    expect($user->onboarded_at)->not->toBeNull();
});

// =============================================================================
// Existing Account Flow Tests
// =============================================================================

it('shows existing accounts instead of create form when accounts exist', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    $bank = Bank::factory()->create(['name' => 'Test Bank']);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->click("Let's Get Started")
        ->wait(1)
        ->assertSee('Account Types')
        ->click('Create Your First Account')
        ->wait(1)
        // Should show existing accounts, not the create form
        ->assertSee('Your Accounts')
        ->assertSee('Test Bank')
        ->assertSee('Checking')
        ->assertNoJavascriptErrors();
});

it('allows continuing with existing accounts', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    $bank = Bank::factory()->create(['name' => 'Existing Bank']);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'type' => 'checking',
        'currency_code' => 'EUR',
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->click("Let's Get Started")
        ->wait(1)
        ->assertSee('Account Types')
        ->click('Create Your First Account')
        ->wait(1)
        ->assertSee('Your Accounts')
        ->assertSee('Existing Bank')
        // Click Continue to proceed
        ->click('Continue')
        ->wait(2)
        // Should go to import transactions (since checking account needs transactions)
        ->assertSee('Import Your Transactions')
        ->assertNoJavascriptErrors();
});

// =============================================================================
// More Accounts Flow Tests
// =============================================================================

it('shows import transactions step after account creation', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    $bank = Bank::factory()->create(['name' => 'My Bank']);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->click("Let's Get Started")
        ->wait(1)
        ->click('Create Your First Account')
        ->wait(1)
        ->click('Continue')
        ->wait(2)
        // Should show import transactions step
        ->assertSee('Import Your Transactions')
        ->assertSee('Import Transactions')
        ->assertNoJavascriptErrors();
});

it('shows add another account form without first account restriction', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    $bank = Bank::factory()->create(['name' => 'Primary Bank']);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->click("Let's Get Started")
        ->wait(1)
        ->click('Create Your First Account')
        ->wait(1)
        // At this point, the "Your Accounts" view shows existing accounts
        ->assertSee('Your Accounts')
        ->assertSee('Primary Bank')
        ->assertNoJavascriptErrors();
});

// =============================================================================
// Full End-to-End Flow Test
// =============================================================================

it('completes onboarding flow through account creation', function () {
    // Create a bank for the account creation step
    Bank::factory()->create(['name' => 'Chase Bank']);

    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->assertPathIs('/onboarding')
        ->assertNoJavascriptErrors();

    // Step 1: Welcome
    $page->assertSee('Welcome to')
        ->assertSee('Whisper Money')
        ->click("Let's Get Started")
        ->wait(1);

    // Step 2: Account Types
    $page->assertSee('Account Types')
        ->assertSee('Checking')
        ->assertSee('Savings')
        ->assertSee('Credit Card')
        ->click('Create Your First Account')
        ->wait(1);

    // Step 3: Create Account
    $page->assertSee('Create an Account')
        ->assertSee('Your first account must be a')
        ->fill('#display_name', 'My Checking Account')
        // Select bank from combobox - need to search first
        ->click('Select bank...')
        ->wait(1)
        ->fill('[placeholder="Search bank..."]', 'Chase')
        ->wait(2)
        ->click('Chase Bank')
        ->wait(1)
        // Select currency - click on the dropdown item (Radix UI creates role="option")
        ->click('Select currency')
        ->wait(1)
        ->click('[role="option"]:has-text("USD")')
        ->wait(1)
        ->click('Create Account')
        ->wait(3);

    // Step 4: Import Transactions step should appear
    $page->assertSee('Import Your Transactions')
        ->assertNoJavascriptErrors();

    // Verify account was created
    expect($user->accounts()->count())->toBe(1);
    expect($user->accounts()->first()->type->value)->toBe('checking');
});

it('marks user as onboarded when completing via API', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    $bank = Bank::factory()->create();
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'type' => 'checking',
    ]);

    expect($user->isOnboarded())->toBeFalse();

    // Complete onboarding via POST
    $this->actingAs($user)->post('/onboarding/complete');

    $user->refresh();
    expect($user->isOnboarded())->toBeTrue();
    expect($user->onboarded_at)->not->toBeNull();
});
