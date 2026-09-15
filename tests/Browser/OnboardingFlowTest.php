<?php

use App\Actions\CreateDefaultCategories;
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

    $page = visit('/onboarding?step=create-account');

    $page->assertPathIs('/onboarding')
        ->wait(1)
        ->assertSee("Let's build the picture")
        ->click('Add one myself')
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

it('opens on the promise rather than a welcome', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->assertSee('Find out where your money actually went')
        ->assertSee('Start')
        ->assertNoJavascriptErrors();
});

it('walks the four questions and reads the answers back', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->click('Start')
        ->wait(1)
        ->assertSee('What do you want to change?')
        ->click('Understand where it all goes')
        ->wait(1)
        ->click('Continue')
        ->wait(1)
        ->assertSee('How do you keep track today?')
        ->click('A spreadsheet')
        ->wait(1)
        ->click('Continue')
        ->wait(1)
        ->assertSee('What did you spend last month?')
        ->click('Lock in my guess')
        ->wait(1)
        // The plan step is the whole point of asking: it hands the answers back.
        ->assertSee("Here's what happens next")
        ->assertSee('understand where it all goes')
        ->assertNoJavascriptErrors();

    expect($user->refresh()->onboarding_answers)->toMatchArray([
        'goal' => 'understand',
        'today' => 'spreadsheet',
    ]);
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

    $page = visit('/onboarding?step=create-account');

    $page->wait(1)
        // Should show the hub with what is already in, not the empty one
        ->assertSee("1 in. What's missing?")
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

    $page = visit('/onboarding?step=create-account');

    $page->wait(1)
        ->assertSee("1 in. What's missing?")
        ->assertSee('Existing Bank')
        // The step ends when the user says it does, not when they add one thing
        ->click("That's everything — continue")
        ->wait(3)
        // Nothing to sync, so the syncing step hands straight over to the
        // reveal, which has no movements to reveal for this user.
        ->assertSee('You’re worth')
        ->click('Continue without one')
        ->wait(2)
        ->assertSee('Let AI draft your rules?')
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
        'state_token' => 'onboarding-failure-token',
    ]);

    $this->actingAs($user);

    // The state token is what attributes the failure to this attempt: an error
    // the callback cannot resolve deletes nothing.
    $page = visit('/open-banking/callback?error=access_denied&error_description=Authentication+failed&state=onboarding-failure-token');

    // The accounts step opens on its failure screen, which names the bank that
    // refused and says what it did not leave behind — not on the hub listing.
    $page->wait(1)
        ->assertPathIs('/onboarding')
        ->assertQueryStringHas('step', 'create-account')
        ->assertSee('Failing Bank didn’t let us in')
        ->assertSee('Nothing was created')
        ->assertSee('Nothing was charged')
        ->assertDontSee('Find out where your money actually went')
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
        // Lands on the accounts hub, skipping the questions entirely.
        ->assertSee("Let's build the picture")
        ->assertSee('Connect a bank')
        ->assertSee('Add one myself')
        ->assertDontSee('Find out where your money actually went')
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
        ->assertSee("Let's build the picture")
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
        ->assertSee("1 in. What's missing?")
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

    $page = visit('/onboarding?step=create-account');

    $page->wait(1)
        ->click("That's everything — continue")
        ->wait(3)
        // Existing accounts no longer trigger import, and there is nothing to
        // sync — so the reveal has nothing to show but the balances.
        ->assertSee('You’re worth')
        ->click('Continue without one')
        ->wait(2)
        ->assertSee('Let AI draft your rules?')
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

    $page = visit('/onboarding?step=create-account');

    $page->wait(1)
        // At this point, the hub lists what the user already has
        ->assertSee("1 in. What's missing?")
        ->assertSee('Primary Bank')
        ->assertNoJavascriptErrors();
});

it('hides the connected plan price after connected setup is selected once', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding?step=create-account');

    // The row no longer says a plan is chosen at the end, because it is not:
    // it names the price and leads to the gate, which is where the plan starts.
    $page->wait(1)
        ->assertSee('Standard plan, from')
        ->assertSee('/month')
        ->assertDontSee("You'll choose a plan at the end of the onboarding.")
        ->click('Connect a bank')
        ->wait(1)
        ->assertSee('Connecting a bank needs Standard')
        ->click('Not now — I’ll add accounts by hand')
        ->wait(1)
        ->assertSee('Create an Account')
        ->click('Back')
        ->wait(1)
        ->assertSee("Let's build the picture")
        ->assertDontSee('Standard plan, from')
        ->assertDontSee('/month')
        ->assertNoJavascriptErrors();
});

it('creates a real estate account during onboarding by default', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding?step=create-account');

    $page->wait(1)
        ->assertSee("Let's build the picture")
        ->click('Add one myself')
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

    // Registration seeds these; the factory does not, and the categorize step
    // has nothing to offer without them.
    app(CreateDefaultCategories::class)->handle($user);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->assertPathIs('/onboarding')
        ->assertNoJavascriptErrors();

    // Steps 1-5: the promise and the four questions
    $page->assertSee('Find out where your money actually went')
        ->click('Start')
        ->wait(1)
        ->click('Understand where it all goes')
        ->wait(1)
        ->click('Continue')
        ->wait(1)
        ->click('In my head')
        ->wait(1)
        ->click('Continue')
        ->wait(1)
        ->click('Lock in my guess')
        ->wait(1)
        ->assertSee("Here's what happens next")
        ->click("Let's go")
        ->wait(1);

    // Step 6: the accounts hub, empty. Take the by-hand route and fill the form.
    $page->assertSee("Let's build the picture")
        ->assertSee('Connect a bank')
        ->click('Add one myself')
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

    // The import is screens of the flow now, and the only account that can take
    // a file is the one just created — so it opens straight on the file.
    $page->assertSee('Bring in your history')
        ->attach('input[type="file"]', __DIR__.'/assets/test-transactions.csv')
        ->wait(2);

    // The columns we guessed, for the user to disagree with.
    $page->assertSee('Did we read it right?')
        ->click("That's right")
        ->wait(3);

    // The preview, where nothing has been written yet.
    $page->assertSee('5 movements')
        ->click('Import 5 movements')
        ->wait(15);

    // After import completes, back to the hub with the account in it
    $page->assertSee('My Checking Account')
        ->assertSee('Usually missed')
        // One click, not two: the import step completes itself, so the hub is
        // already the screen by the time this runs.
        ->click("That's everything — continue")
        ->wait(3); // syncing step reloads transactions — allow time for axios + router.reload

    // The reveal: five movements in one month is under the bar, so this user
    // gets the balances variant.
    $page->assertSee('You’re worth')
        ->click('Continue without one')
        ->wait(2);

    // AI Suggestions - the AI cannot be switched on without a plan, so this
    // user meets the gate rather than the consent prompt. Decline it and the
    // flow carries on unpaid, which is the whole point of the way past it.
    $page->assertSee('AI sorting needs Standard')
        ->click('I’ll sort them myself')
        ->wait(2);

    // Teach us - the movements the rules could not place. Skipping is no longer
    // a way through the step: the minimum has to be filed for real.
    $page->assertSee('Teach us your habits')
        ->assertSee('File 5 movements to carry on')
        ->wait(1);

    // The rules hint opens over the category list after the first one, and
    // holds it disabled until it is acknowledged.
    $page->click('Food')
        ->wait(2)
        ->click('Got it')
        ->wait(1);

    foreach (range(1, 4) as $ignored) {
        $page->click('Food')->wait(2);
    }

    $page->assertSee('That’s enough to continue')
        ->click('Continue')
        ->wait(1);

    // Step 10 has no month to build a target on for this user — five movements
    // never got revealed — so it steps aside rather than invent a number, and
    // the flow lands on the close itself.
    $page->assertSee('Your dashboard isn’t empty')
        ->assertDontSee('put aside')
        ->click('Open my dashboard')
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

    // The questions asked on the way in are kept, guess included
    expect($user->onboarding_answers)->toMatchArray([
        'goal' => 'understand',
        'today' => 'head',
        'spending_guess' => 120000,
    ]);

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
// AI Suggestions Consent Tests
// =============================================================================

it('activates AI directly without a consent prompt when a bank is connected', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->subscribed()->create(['onboarded_at' => null]);

    $bank = Bank::factory()->create(['name' => 'Connected AI Bank']);
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'banking_connection_id' => $connection->id,
        'type' => 'checking',
        'currency_code' => 'EUR',
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding?step=ai-suggestions');

    // A bank cannot be linked without paying for it first, so this user has a
    // plan: the consent prompt is skipped and AI is turned on for them. With no
    // transactions yet the run stops at the "need more data" screen instead of
    // calling the AI.
    $page->wait(3)
        ->assertDontSee('Let AI draft your rules?')
        ->assertDontSee('Turn it on')
        ->assertSee('Not enough to learn from yet')
        ->assertNoJavascriptErrors();

    expect($user->refresh()->hasActiveAiConsent())->toBeTrue();
});

it('asks for consent before activating AI when the plan was bought without a bank', function () {
    config(['subscriptions.enabled' => true]);

    // Paid, but with nothing connected — the bank gate's checkout came back and
    // the authorization never happened, or the plan was bought from the paywall.
    // Nothing has taken their consent yet, so the prompt is still theirs to answer.
    $user = User::factory()->subscribed()->create(['onboarded_at' => null]);

    $this->actingAs($user);

    expect($user->hasActiveAiConsent())->toBeFalse();

    $page = visit('/onboarding?step=ai-suggestions');

    $page->wait(2)
        ->assertSee('Let AI draft your rules?')
        ->assertSee('Without this you sort')
        ->assertSee('Turn it on')
        ->assertNoJavascriptErrors();

    // Nothing is activated until they explicitly accept.
    expect($user->refresh()->hasActiveAiConsent())->toBeFalse();

    $page->click('Turn it on')
        ->wait(3)
        ->assertNoJavascriptErrors();

    expect($user->refresh()->hasActiveAiConsent())->toBeTrue();
});

// =============================================================================
// Subscribe Page Free Plan Tests
// =============================================================================

it('shows free plan option on subscribe page when no bank was connected', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();

    $this->actingAs($user);

    $page = visit('/subscribe');

    // The soft gate: nothing was connected and nothing was switched on, so the
    // way out is offered from the first paint rather than after a delay.
    $page->assertPathIs('/subscribe')
        ->assertSee('One thing left to decide')
        ->assertSee('Carry on free')
        ->assertDontSee('Need help?')
        ->assertNoJavascriptErrors();
});

it('forces a plan choice on subscribe when a bank is connected', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    $page = visit('/subscribe');

    // A connected bank is what the free plan would have to cut, so that door
    // waits out the delay after onboarding. Until then there is a plan to buy
    // and a way to ask for help, and nothing else.
    $page->assertPathIs('/subscribe')
        ->assertSee('Start Standard')
        ->assertSee('Need help?')
        ->assertDontSee('Carry on free')
        ->assertDontSee('Stay on the free plan')
        ->assertNoJavascriptErrors();
});

it('forces a plan choice on subscribe when AI consent is active', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    $user->recordAiConsent();

    $this->actingAs($user);

    $page = visit('/subscribe');

    // AI switched on does the same as a bank: the free plan would revoke it, so
    // the way out waits and the plan is the only thing on offer until it opens.
    $page->assertPathIs('/subscribe')
        ->assertSee('Start Standard')
        ->assertSee('Need help?')
        ->assertDontSee('Carry on free')
        ->assertDontSee('Stay on the free plan')
        ->assertNoJavascriptErrors();
});
