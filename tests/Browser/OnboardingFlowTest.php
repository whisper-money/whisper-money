<?php

use App\Actions\CreateDefaultCategories;
use App\Models\Account;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;

// =============================================================================
// Signup Tests
// =============================================================================

it('syncs user currency from first onboarding account after signup', function () {
    Bank::factory()->create(['name' => 'Signup Test Bank']);

    $page = visit('/register?force=1');

    $page->assertSee('Create an account')
        ->fill('name', 'Currency Signup User')
        ->fill('email', 'currency-signup@example.com')
        ->fill('password', 'password123456')
        ->fill('password_confirmation', 'password123456')
        ->click('@register-user-button')
        ->assertPathIs('/email/verify')
        ->assertNoJavascriptErrors();

    $user = User::where('email', 'currency-signup@example.com')->firstOrFail();

    expect($user->currency_code)->toBe('USD');

    $user->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($user->refresh());

    $page = visit('/onboarding?step=create-account');

    $page->assertPathIs('/onboarding')
        ->assertSee("Let's build the picture")
        ->click('Add one myself')
        ->fill('#display_name', 'Euro Checking Account')
        ->click('Select bank...')
        ->fill('[placeholder="Search bank..."]', 'Signup')
        ->click('Signup Test Bank')
        ->click('Select account type')
        ->click('[role="option"]:has-text("Checking")')
        ->click('button[name="currency_code"]')
        ->click('[role="option"]:has-text("EUR")')
        ->click('Add this account')
        ->assertSee('Bring in your history')
        ->assertNoJavascriptErrors();

    $user->refresh();
    $account = $user->accounts()->first();

    expect($user->currency_code)->toBe('EUR');
    expect($account)->not->toBeNull();
    expect($account->currency_code)->toBe('EUR');
});

// =============================================================================
// Existing Account Flow Tests
// =============================================================================

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

    $page->assertSee("1 in. What's missing?")
        ->assertSee('Existing Bank')
        // The step ends when the user says it does, not when they add one thing
        ->click("That's everything — continue")
        // Nothing to sync, so the syncing step hands straight over to the
        // reveal, which has no movements to reveal for this user.
        ->assertSee('You’re worth')
        // The reveal's own button, which is just "Continue" for a reader with
        // no month to read: the step after it depends on their plan, so what is
        // asserted here is that the flow leaves the reveal at all.
        ->click('Continue')
        ->assertDontSee('You’re worth')
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
    $page->assertPathIs('/onboarding')
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

    $page
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
    $page->assertSee("Let's build the picture")
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
    $page->assertSee("1 in. What's missing?")
        ->assertSee('Polled Bank')
        ->assertNoJavascriptErrors();
});

// =============================================================================
// More Accounts Flow Tests
// =============================================================================

it('hides the connected plan price after connected setup is selected once', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding?step=create-account');

    // The row no longer says a plan is chosen at the end, because it is not:
    // it names the price and leads to the gate, which is where the plan starts.
    $page->assertSee('Standard plan, from')
        ->assertSee('/month')
        ->assertDontSee("You'll choose a plan at the end of the onboarding.")
        ->click('Connect a bank')
        ->assertSee('Connecting a bank needs Standard')
        ->click('Not now — I’ll add accounts by hand')
        ->assertSee('Add it yourself')
        ->click('Back')
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

    $page->assertSee("Let's build the picture")
        ->click('Add one myself')
        ->fill('#display_name', 'My Apartment')
        ->click('Select account type')
        ->click('[role="option"]:has-text("Real Estate")')
        ->click('button[name="currency_code"]')
        ->click('[role="option"]:has-text("EUR")')
        ->click('Select property type')
        ->click('[role="option"]:has-text("Residential")')
        ->click('Add this account')
        ->assertSee('Set Account Balance')
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

/**
 * A mortgage is a balance and nothing else, and the form asks for that balance.
 * The step behind it asks for the same number again, which reads as the first
 * answer having gone nowhere — so it is skipped for anyone who already gave one.
 */
it('does not ask for a balance twice when the form already took one', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    visit('/onboarding?step=create-account')
        ->assertSee("Let's build the picture")
        ->click('Add one myself')
        ->fill('#display_name', 'Hipoteca')
        ->click('Select account type')
        ->click('[role="option"]:has-text("Loan")')
        ->click('button[name="currency_code"]')
        ->click('[role="option"]:has-text("EUR")')
        ->fill('#balance', '120000')
        ->click('Add this account')
        ->assertSee("What's missing?")
        ->assertDontSee('Set Account Balance')
        ->assertNoJavascriptErrors();

    expect($user->accounts()->count())->toBe(1);
});

it('still asks for a balance when the form was left without one', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    visit('/onboarding?step=create-account')
        ->click('Add one myself')
        ->fill('#display_name', 'Plan de pensiones')
        ->click('Select account type')
        ->click('[role="option"]:has-text("Loan")')
        ->click('button[name="currency_code"]')
        ->click('[role="option"]:has-text("EUR")')
        ->click('Add this account')
        ->assertSee('Set Account Balance')
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
        ->click('Understand where it all goes')
        ->click('Continue')
        ->click('In my head')
        ->click('Continue')
        ->click('Lock in my guess')
        ->assertSee("Here's what happens next")
        ->click("Let's go");

    // Step 6: the accounts hub, empty. Take the by-hand route and fill the form.
    $page->assertSee("Let's build the picture")
        ->assertSee('Connect a bank')
        ->click('Add one myself')
        ->fill('#display_name', 'My Checking Account')
        ->click('Select bank...')
        ->fill('[placeholder="Search bank..."]', 'Chase')
        ->click('Chase Bank')
        ->click('Select account type')
        ->click('[role="option"]:has-text("Checking")')
        ->click('button[name="currency_code"]')
        ->click('[role="option"]:has-text("EUR")')
        ->click('Add this account');

    // The import is screens of the flow now, and the only account that can take
    // a file is the one just created — so it opens straight on the file.
    $page->assertSee('Bring in your history')
        ->attach('input[type="file"]', __DIR__.'/assets/test-transactions.csv');

    // The columns we guessed, for the user to disagree with.
    $page->assertSee('Did we read it right?')
        ->click("That's right");

    // The preview, where nothing has been written yet.
    $page->assertSee('5 movements')
        ->click('Import 5 movements');

    // After import completes, back to the hub with the account in it
    $page->assertSee('My Checking Account')
        ->assertSee('Usually missed')
        // One click, not two: the import step completes itself, so the hub is
        // already the screen by the time this runs.
        ->click("That's everything — continue");

    // The reveal: five movements in one month is under the bar, so this user
    // gets the balances variant.
    $page->assertSee('You’re worth')
        ->click('Continue');

    // AI Suggestions - the AI cannot be switched on without a plan, so this
    // user meets the gate rather than the consent prompt. Decline it and the
    // flow carries on unpaid, which is the whole point of the way past it.
    $page->assertSee('AI sorting needs Standard')
        ->click('I’ll sort them myself');

    // Teach us - the movements the rules could not place. Skipping is no longer
    // a way through the step: the minimum has to be filed for real.
    $page->assertSee('Teach us your habits')
        ->assertSee('File 5 movements to carry on');

    // The rules hint opens over the category list after the first one, and
    // holds it disabled until it is acknowledged.
    $page->click('Food')
        ->click('Got it');

    foreach (range(1, 4) as $filed) {
        $page->click('Food');

        // The counter is what proves the movement was filed before the next click.
        if ($filed < 4) {
            $page->assertSee(($filed + 1).'/5');
        }
    }

    $page->assertSee('That’s enough to continue')
        ->click('Continue');

    // Step 10 has no month to build a target on for this user — five movements
    // never got revealed — so it says so rather than invent a number. It is
    // still a screen: step 5 promised them a target, and vanishing between the
    // step before and the step after would read as one that broke.
    $page->assertSee('Your target can wait')
        ->click('Continue');

    $page->assertSee('Your dashboard isn’t empty')
        ->assertDontSee('put aside')
        ->click('Open my dashboard');

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
    $page->assertSee('Not enough to learn from yet')
        ->assertDontSee('Let AI draft your rules?')
        ->assertDontSee('Turn it on')
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

    $page->assertSee('Let AI draft your rules?')
        ->assertSee('Without this you sort')
        ->assertSee('Turn it on')
        ->assertNoJavascriptErrors();

    // Nothing is activated until they explicitly accept.
    expect($user->refresh()->hasActiveAiConsent())->toBeFalse();

    $page->click('Turn it on')
        ->assertSee('Not enough to learn from yet')
        ->assertNoJavascriptErrors();

    expect($user->refresh()->hasActiveAiConsent())->toBeTrue();
});

// =============================================================================
// Subscribe Page Free Plan Tests
// =============================================================================

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
