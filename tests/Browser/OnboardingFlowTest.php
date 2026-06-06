<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;

// =============================================================================
// Basic Redirect Tests
// =============================================================================

it('redirects new registration to email verification', function () {
    $page = visit('/register?force=1');

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

it('syncs user currency from first onboarding account after signup', function () {
    Bank::factory()->create(['name' => 'Signup Test Bank']);

    $page = visit('/register?force=1');

    $page->assertSee('Create an account')
        ->fill('name', 'Currency Signup User')
        ->fill('email', 'currency-signup@example.com')
        ->fill('password', 'password123456')
        ->fill('password_confirmation', 'password123456')
        ->click('@register-user-button')
        ->wait(3)
        ->assertPathIs('/email/verify')
        ->assertNoJavascriptErrors();

    $user = User::where('email', 'currency-signup@example.com')->firstOrFail();

    expect($user->currency_code)->toBe('USD');

    $user->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($user->refresh());

    $page = visit('/onboarding');

    $page->assertPathIs('/onboarding')
        ->assertSee('Welcome to')
        ->click("Let's Get Started")
        ->wait(1)
        ->assertSee('Account Types')
        ->click('Create Your First Account')
        ->wait(1)
        ->assertSee('Create an Account')
        ->click('Manual')
        ->wait(1)
        ->click('Continue')
        ->wait(1)
        ->fill('#display_name', 'Euro Checking Account')
        ->click('Select bank...')
        ->wait(1)
        ->fill('[placeholder="Search bank..."]', 'Signup')
        ->wait(1)
        ->click('Signup Test Bank')
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
        ->wait(5)
        ->assertNoJavascriptErrors();

    $user->refresh();
    $account = $user->accounts()->first();

    expect($user->currency_code)->toBe('EUR');
    expect($account)->not->toBeNull();
    expect($account->currency_code)->toBe('EUR');
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

it('shows real estate on onboarding account types by default', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->click("Let's Get Started")
        ->wait(1)
        ->assertSee('Account Types')
        ->assertSee('Balance')
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
        // Should go to category types (existing accounts no longer trigger import)
        ->assertSee('Understanding Categories')
        ->assertNoJavascriptErrors();
});

it('returns to the accounts step when bank authorization fails during onboarding', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $bank = Bank::factory()->create(['name' => 'Connected Bank']);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'type' => 'checking',
        'currency_code' => 'EUR',
    ]);
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Failing Bank',
        'aspsp_country' => 'ES',
    ]);

    $this->actingAs($user);

    $page = visit('/open-banking/callback?error=access_denied&error_description=Authentication+failed');

    $page->wait(1)
        ->assertPathIs('/onboarding')
        ->assertQueryStringHas('step', 'create-account')
        ->assertSee('Your Accounts')
        ->assertSee('Connected Bank')
        ->assertDontSee('Welcome to')
        ->assertNoJavascriptErrors();

    $connection->refresh();
    expect($connection->trashed())->toBeTrue();
});

it('deep links straight to the connections step via ?step=create-account', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding?step=create-account');

    $page->wait(1)
        // Lands on the connections step, skipping the welcome step entirely.
        ->assertSee('Create an Account')
        ->assertSee('Manual')
        ->assertDontSee('Welcome to')
        ->assertNoJavascriptErrors();
});

it('polls and shows a connection finalized in another browser', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    // User sits on the connections step with no accounts yet.
    $page = visit('/onboarding?step=create-account');
    $page->wait(1)
        ->assertSee('Create an Account')
        ->assertDontSee('Polled Bank');

    // The bank flow is finalized elsewhere (iOS PWA -> Safari): an account is
    // created server-side without this browser doing anything.
    $bank = Bank::factory()->create(['name' => 'Polled Bank']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
    ]);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'banking_connection_id' => $connection->id,
        'type' => 'checking',
        'currency_code' => 'EUR',
    ]);

    // The 4s poll picks it up and the connection appears without a manual refresh.
    $page->wait(6)
        ->assertSee('Your Accounts')
        ->assertSee('Polled Bank')
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
        // Should go to category types (existing accounts no longer trigger import)
        ->assertSee('Understanding Categories')
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

it('hides the connected plan warning after connected setup is selected once', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $warning = "Connected accounts are a Standard Plan feature. You'll choose a plan at the end of the onboarding.";

    $page = visit('/onboarding');

    $page->click("Let's Get Started")
        ->wait(1)
        ->click('Create Your First Account')
        ->wait(1)
        ->assertSee($warning)
        ->assertSee('/month')
        ->click('Continue')
        ->wait(1)
        ->assertSee('Connect Your Bank')
        ->click('Back')
        ->wait(1)
        ->assertSee('How would you like to set up this account?')
        ->assertDontSee($warning)
        ->assertDontSee('/month')
        ->assertNoJavascriptErrors();
});

it('creates a real estate account during onboarding by default', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->click("Let's Get Started")
        ->wait(1)
        ->click('Create Your First Account')
        ->wait(1)
        ->assertSee('Create an Account')
        ->click('Manual')
        ->wait(1)
        ->click('Continue')
        ->wait(1)
        ->fill('#display_name', 'My Apartment')
        ->click('Select account type')
        ->wait(1)
        ->click('[role="option"]:has-text("Real Estate")')
        ->wait(1)
        ->click('Select currency')
        ->wait(1)
        ->click('[role="option"]:has-text("EUR")')
        ->wait(1)
        ->click('Select property type')
        ->wait(1)
        ->click('[role="option"]:has-text("Residential")')
        ->wait(1)
        ->click('Create Account')
        ->wait(5)
        ->assertNoJavascriptErrors();

    $user->refresh();

    $account = $user->accounts()->first();

    expect($account)->not->toBeNull();
    expect($account->type->value)->toBe('real_estate');
    expect($account->name)->toBe('My Apartment');
    expect($account->currency_code)->toBe('EUR');
    expect($account->bank_id)->toBeNull();
    expect($account->realEstateDetail)->not->toBeNull();
    expect($account->realEstateDetail->property_type->value)->toBe('residential');
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

    // Step 3: Create Account - connected mode is preselected, switch to manual and fill the form
    $page->assertSee('Create an Account')
        ->assertSee('Manual')
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

    // After import completes, back to create-account step in list mode
    $page->assertSee('My Checking Account')
        ->click('Continue')
        ->wait(1);

    // Category Types
    $page->assertSee('Understanding Categories')
        ->click('Continue')
        ->wait(1);

    // Smart Rules
    $page->assertSee('Smart Automation Rules')
        ->click('Continue')
        ->wait(3); // syncing step reloads transactions — allow time for axios + router.reload

    // Categorize Transactions - 5 CSV transactions are loaded after the syncing step reloads
    $page->assertSee('Categorize Your Transactions')
        ->click("Let's start")
        ->wait(1)
        ->click('button:has-text("Skip")')->wait(1)
        ->click('button:has-text("Skip")')->wait(1)
        ->click('button:has-text("Skip")')->wait(1)
        ->click('button:has-text("Skip")')->wait(1)
        ->click('button:has-text("Skip")')->wait(1)
        ->click('Continue')
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

it('shows free plan option on subscribe page when no bank was connected', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();

    $this->actingAs($user);

    $page = visit('/subscribe');

    $page->assertPathIs('/subscribe')
        ->assertSee('Continue for free')
        ->assertNoJavascriptErrors();
});
