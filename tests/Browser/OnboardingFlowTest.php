<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\User;
use Laravel\Pennant\Feature;

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

// =============================================================================
// Existing Account Flow Tests
// =============================================================================

it('shows existing accounts instead of create form when accounts exist', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
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

it('completes entire onboarding flow with account creation, transaction import, and ends on subscribe page', function () {
    // Enable subscriptions so user ends on /subscribe after completing onboarding
    config(['subscriptions.enabled' => true]);

    Bank::factory()->create(['name' => 'Chase Bank']);

    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    Feature::for($user)->activate('open-banking');

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
        ->click('Create Your First Account')
        ->wait(1);

    // Step 3: Create Account - select Manual mode then fill the form
    $page->assertSee('Create an Account')
        ->assertSee('Manual')
        ->assertSee('Connected')
        ->click('Manual')
        ->wait(1)
        ->click('Continue')
        ->wait(1)
        ->fill('#display_name', 'My Checking Account')
        ->click('Select bank...')
        ->wait(1)
        ->fill('[placeholder="Search bank..."]', 'Chase')
        ->wait(2)
        ->click('Chase Bank')
        ->wait(1)
        ->click('Select account type')
        ->wait(1)
        ->click('[role="option"]:has-text("Checking")')
        ->wait(1)
        ->click('Select currency')
        ->wait(1)
        ->click('[role="option"]:has-text("EUR")')
        ->wait(1)
        ->click('Create Account')
        ->wait(5);

    // Step 4: Import Transactions - open the import drawer
    $page->assertSee('Import Your Transactions')
        ->click('Import Transactions')
        ->wait(3);

    // The drawer auto-selects the only account and moves to Upload File step
    // Upload the test CSV file
    $csvPath = __DIR__.'/assets/test-transactions.csv';
    $page->attach('input[type="file"]', $csvPath)
        ->wait(2)
        ->click('Next')
        ->wait(2);

    // Column Mapping step (auto-detected: Date, Description, Amount)
    $page->assertSee('Map Columns')
        ->click('Preview Transactions')
        ->wait(3);

    // Preview step - import all 5 transactions from the CSV
    $page->assertSee('Preview Transactions')
        ->click('Import 5 Transactions')
        ->wait(15);

    // After import completes, drawer closes and transitions to Category Types
    $page->assertSee('Understanding Categories')
        ->click('Continue')
        ->wait(1);

    // Smart Rules
    $page->assertSee('Smart Automation Rules')
        ->click('Continue')
        ->wait(1);

    // More Accounts - verify account is listed, then finish
    $page->assertSee('Great Progress!')
        ->assertSee('My Checking Account')
        ->click('Finish Setup')
        ->wait(1);

    // Complete step
    $page->assertSee("You're All Set!")
        ->click('Go to Dashboard')
        ->wait(5);

    // Since SUBSCRIPTIONS_ENABLED is true, user should end on /subscribe
    $page->assertPathIs('/subscribe')
        ->assertNoJavascriptErrors();

    // === Database Assertions ===
    $user->refresh();

    // User should be marked as onboarded
    expect($user->isOnboarded())->toBeTrue();
    expect($user->onboarded_at)->not->toBeNull();

    // User currency_code should match the first account's currency
    expect($user->currency_code)->toBe('EUR');

    // Account should exist with correct properties
    $account = $user->accounts()->first();
    expect($account)->not->toBeNull();
    expect($account->type->value)->toBe('checking');
    expect($account->currency_code)->toBe('EUR');
    expect($account->name)->toBe('My Checking Account');

    // Transactions should be imported in the correct account
    $transactions = $user->transactions()->where('account_id', $account->id)->get();
    expect($transactions)->toHaveCount(5);
    expect($transactions->pluck('currency_code')->unique()->first())->toBe('EUR');
});

// =============================================================================
// Subscribe Page Free Plan Tests
// =============================================================================

it('shows free plan option on subscribe page when open banking is enabled and no bank was connected', function () {
    // Enable subscriptions and open banking
    config(['subscriptions.enabled' => true]);

    Bank::factory()->create(['name' => 'Chase Bank']);

    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    // Activate open-banking feature for this user
    Feature::for($user)->activate('open-banking');

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
        ->click('Create Your First Account')
        ->wait(1);

    // Step 3: Create Account - select Manual mode and fill the form
    $page->assertSee('Create an Account')
        ->assertSee('Manual')
        ->assertSee('Connected')
        ->click('Manual')
        ->wait(1)
        ->click('Continue')
        ->wait(1)
        ->fill('#display_name', 'My Checking Account')
        ->click('Select bank...')
        ->wait(1)
        ->fill('[placeholder="Search bank..."]', 'Chase')
        ->wait(2)
        ->click('Chase Bank')
        ->wait(1)
        ->click('Select account type')
        ->wait(1)
        ->click('[role="option"]:has-text("Checking")')
        ->wait(1)
        ->click('Select currency')
        ->wait(1)
        ->click('[role="option"]:has-text("EUR")')
        ->wait(1)
        ->click('Create Account')
        ->wait(5);

    // Step 4: Import Transactions
    $page->assertSee('Import Your Transactions')
        ->click('Import Transactions')
        ->wait(3);

    $csvPath = __DIR__.'/assets/test-transactions.csv';
    $page->attach('input[type="file"]', $csvPath)
        ->wait(2)
        ->click('Next')
        ->wait(2);

    $page->assertSee('Map Columns')
        ->click('Preview Transactions')
        ->wait(3);

    $page->assertSee('Preview Transactions')
        ->click('Import 5 Transactions')
        ->wait(15);

    // Step 5: Category Types
    $page->assertSee('Understanding Categories')
        ->click('Continue')
        ->wait(1);

    // Step 6: Smart Rules
    $page->assertSee('Smart Automation Rules')
        ->click('Continue')
        ->wait(1);

    // Step 7: More Accounts - finish setup
    $page->assertSee('Great Progress!')
        ->click('Finish Setup')
        ->wait(1);

    // Step 8: Complete
    $page->assertSee("You're All Set!")
        ->click('Go to Dashboard')
        ->wait(5);

    // Should land on /subscribe since subscriptions are enabled
    $page->assertPathIs('/subscribe')
        ->assertNoJavascriptErrors();

    // Free plan option must be visible because open banking is enabled and no bank was connected
    $page->assertSee('Continue for free');
});

it('does not show free plan option on subscribe page when user connected a bank during onboarding', function () {
    // Enable subscriptions and open banking
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    // Activate open-banking and account-mapping features for this user
    Feature::for($user)->activate('open-banking');
    Feature::for($user)->activate('account-mapping');

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
        ->click('Create Your First Account')
        ->wait(1);

    // Step 3: Create Account - select Connected mode
    $page->assertSee('Create an Account')
        ->assertSee('Connected')
        ->click('Connected')
        ->wait(1)
        ->click('Continue')
        ->wait(1);

    // Step 3a: Connect bank - select country Spain
    $page->assertSee('Connect Your Bank')
        ->click('Select country')
        ->wait(1)
        ->click('[role="option"]:has-text("Spain")')
        ->wait(1)
        ->click('Continue')
        ->wait(5);

    // Step 3b: Select BBVA from the bank list
    $page->assertSee('Search banks...')
        ->fill('[placeholder="Search banks..."]', 'BBVA')
        ->wait(2)
        ->click('BBVA')
        ->wait(1)
        ->click('Continue')
        ->wait(1);

    // Step 3c: Confirm - click Connect to be redirected to EnableBanking sandbox
    $page->assertSee('You will be redirected to authorize access to your account data.')
        ->click('Connect')
        ->wait(10);

    // EnableBanking sandbox: fill in credentials
    // The sandbox presents a login form with username and password fields
    $page->fill('[name="username"], [id="username"], input[type="text"]', 'user1')
        ->wait(1)
        ->fill('[name="password"], [id="password"], input[type="password"]', '1234')
        ->wait(1)
        ->click('button[type="submit"], input[type="submit"], button:has-text("Login"), button:has-text("Sign in"), button:has-text("Submit")')
        ->wait(5);

    // OTC / one-time code step (sandbox may present a second factor)
    $page->fill('[name="otp"], [name="otc"], [name="code"], input[type="text"]', '012345')
        ->wait(1)
        ->click('button[type="submit"], input[type="submit"], button:has-text("Submit"), button:has-text("Confirm"), button:has-text("Continue")')
        ->wait(10);

    // Should be redirected back to /onboarding?step=create-account
    $page->assertPathIs('/onboarding')
        ->assertNoJavascriptErrors();

    // The account is now connected - continue through onboarding
    // Step 4: Category Types (connected accounts skip import steps)
    $page->assertSee('Understanding Categories')
        ->click('Continue')
        ->wait(1);

    // Step 5: Smart Rules
    $page->assertSee('Smart Automation Rules')
        ->click('Continue')
        ->wait(1);

    // Step 6: More Accounts - finish setup
    $page->assertSee('Great Progress!')
        ->click('Finish Setup')
        ->wait(1);

    // Step 7: Complete
    $page->assertSee("You're All Set!")
        ->click('Go to Dashboard')
        ->wait(5);

    // Should land on /subscribe since subscriptions are enabled
    $page->assertPathIs('/subscribe')
        ->assertNoJavascriptErrors();

    // Free plan option must NOT be visible because a bank was connected
    $page->assertDontSee('Continue for free');

    // === Database Assertions ===
    $user->refresh();
    expect($user->isOnboarded())->toBeTrue();
    expect($user->bankingConnections()->exists())->toBeTrue();
});
