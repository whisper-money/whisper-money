<?php

declare(strict_types=1);

use App\Enums\BankingConnectionStatus;
use App\Enums\SuggestionRunStatus;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\RuleSuggestion;
use App\Models\SuggestionRun;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;

use function Pest\Laravel\actingAs;

/**
 * The onboarding steps that are only ever looked at, pressed for real.
 *
 * The flow is the most-visited part of the browser suite, but several of its
 * screens are covered by tests that assert what is written on them and then
 * leave: nobody accepts an AI rule, nobody sets a target, nobody files a
 * movement and checks it landed. These walk those steps the way a user does and
 * assert the database afterwards, which is the half a screenshot cannot prove.
 *
 * @see OnboardingFlowTest for the happy path end to end and the plan gates
 * @see OnboardingAiStatesScreenshotsTest for the six states step 8 renders
 * @see OnboardingCloseScreenshotsTest for the inventory the last screen lists
 */
beforeEach(function () {
    // The paywall and the gates in front of these steps have their own tests;
    // what is being exercised here is the step behind them.
    config(['subscriptions.enabled' => false]);
});

/** Somebody mid-onboarding, reading English and paying in euros. */
function onboardingStepUser(array $answers = []): User
{
    return User::factory()->notOnboarded()->create([
        'email_verified_at' => now(),
        'currency_code' => 'EUR',
        // A Spanish number format on an English screen is the test's own
        // artefact rather than the app's, so the figures are read in one locale.
        'format_locale' => 'en-US',
        'onboarding_answers' => $answers,
    ]);
}

/** A current account, which is the only kind these steps read. */
function onboardingStepAccount(User $user, ?BankingConnection $connection = null): Account
{
    return Account::factory()->for($user)->create([
        'name' => 'Cuenta Nómina',
        'type' => 'checking',
        'currency_code' => 'EUR',
        'banking_connection_id' => $connection?->id,
    ]);
}

/** The same merchant, over and over, which is what a rule is made of. */
function onboardingStepCharges(
    Account $account,
    string $merchant,
    int $times,
    int $amount,
    ?CarbonInterface $date = null,
): void {
    Transaction::factory()
        ->count($times)
        ->for($account->user)
        ->for($account)
        ->create([
            'category_id' => null,
            'creditor_name' => $merchant,
            'amount' => -$amount,
            'currency_code' => 'EUR',
            'transaction_date' => $date ?? now()->subDays(20),
        ]);
}

/** A run that came back, with one card per category it wants to file into. */
function onboardingStepSuggestions(User $user, array $rules): SuggestionRun
{
    $run = SuggestionRun::factory()->for($user)->create([
        'status' => SuggestionRunStatus::Completed,
        'merchants_considered' => count($rules),
        'suggestions_count' => count($rules),
    ]);

    foreach ($rules as [$token, $categoryId, $confidence, $operator]) {
        RuleSuggestion::factory()->for($run, 'run')->create([
            'match_field' => 'creditor_name',
            'match_operator' => $operator,
            'match_token' => $token,
            'group_key' => $token,
            'proposed_category_id' => $categoryId,
            'confidence' => $confidence,
            'sample_descriptions' => [mb_strtoupper($token).' 0001'],
        ]);
    }

    return $run;
}

/** How many of a merchant's movements ended up with a category on them. */
function onboardingStepFiled(User $user, string $merchant): int
{
    return $user->transactions()
        ->where('creditor_name', $merchant)
        ->whereNotNull('category_id')
        ->count();
}

// =============================================================================
// Step 8: the AI suggestions nobody had ever pressed a button on
// =============================================================================

/**
 * The screen's whole purpose is that the rules are the user's to refuse, and
 * until now every test of it stopped at reading the list.
 */
it('creates only the rules the user kept and files the movements behind them', function () {
    $user = onboardingStepUser();
    $account = onboardingStepAccount($user);

    $groceries = Category::factory()->for($user)->create(['name' => 'Groceries', 'type' => 'expense']);
    $eatingOut = Category::factory()->for($user)->create(['name' => 'Eating out', 'type' => 'expense']);
    $shopping = Category::factory()->for($user)->create(['name' => 'Shopping', 'type' => 'expense']);

    // Distinct counts, so the cards are ordered by something stable.
    onboardingStepCharges($account, 'MERCADONA', 18, 6200);
    onboardingStepCharges($account, 'GLOVO', 14, 2220);
    onboardingStepCharges($account, 'AMAZON', 12, 2380);

    $user->recordAiConsent();

    onboardingStepSuggestions($user, [
        ['mercadona', $groceries->id, 0.95, 'equals'],
        ['glovo', $eatingOut->id, 0.92, 'equals'],
        // Under the auto-select bar: shown, but left unticked to opt into.
        ['amazon', $shopping->id, 0.45, 'equals'],
    ]);

    actingAs($user);

    $page = visit('/onboarding?step=ai-suggestions');

    $page->assertSee('3 rules, ready when you are')
        ->assertSee('Groceries')
        ->assertSee('Eating out')
        ->assertSee('Shopping')
        // Two of the three came back confident enough to be pre-ticked.
        ->assertSee('Apply 2 rules')
        // Refuse the one that was ticked for them...
        ->click('[data-testid="suggestion-toggle-cat:'.$eatingOut->id.'"]')
        ->assertSee('Apply 1 rule')
        // ...and opt into the one the run was unsure about.
        ->click('[data-testid="suggestion-toggle-cat:'.$shopping->id.'"]')
        ->assertSee('Apply 2 rules')
        ->click('Apply 2 rules')
        ->assertSee('Your rules are live')
        ->assertSee('We created 2 rules and filed 30 movements')
        ->assertNoJavascriptErrors();

    $rules = $user->automationRules()->get();

    expect($rules)->toHaveCount(2)
        ->and($rules->pluck('action_category_id')->all())
        ->toContain($groceries->id, $shopping->id)
        ->not->toContain($eatingOut->id);

    // The rules ran over the import there and then, and the merchant that was
    // unticked is untouched by both of them.
    expect(onboardingStepFiled($user, 'MERCADONA'))->toBe(18)
        ->and(onboardingStepFiled($user, 'AMAZON'))->toBe(12)
        ->and(onboardingStepFiled($user, 'GLOVO'))->toBe(0);
});

/**
 * A token the run drew too wide, narrowed by hand before it is created. The
 * card's own count is what tells the user what they just changed, so it is also
 * what the test waits on.
 */
it('applies a rule the user narrowed by hand', function () {
    $user = onboardingStepUser();
    $account = onboardingStepAccount($user);

    $groceries = Category::factory()->for($user)->create(['name' => 'Groceries', 'type' => 'expense']);

    onboardingStepCharges($account, 'MERCADONA', 18, 6200);
    onboardingStepCharges($account, 'MERCADONA EXPRESS', 12, 1850);
    // Enough movements behind them to clear the eligibility bar.
    onboardingStepCharges($account, 'REPSOL', 14, 5400);

    $user->recordAiConsent();

    onboardingStepSuggestions($user, [
        ['mercadona', $groceries->id, 0.95, 'contains'],
    ]);

    actingAs($user);

    $page = visit('/onboarding?step=ai-suggestions');

    $page->assertSee('1 rule, ready when you are')
        // As drafted it would take both shops at once.
        ->assertSee('30 movements')
        // The card opens on its own summary row, which is the only one here.
        ->click('button[aria-expanded="false"]')
        ->assertSee('If the transaction matches any of')
        ->fill('[aria-label="Match text"]', 'mercadona express')
        // The count is recomputed against the edited token, which is the only
        // thing telling the user what the rule now covers.
        ->assertSee('12 movements')
        ->click('Apply 1 rule')
        ->assertSee('Your rules are live')
        ->assertSee('We created 1 rule and filed 12 movements')
        ->assertNoJavascriptErrors();

    $rule = $user->automationRules()->sole();

    expect($rule->title)->toBe('Mercadona Express → Groceries')
        ->and($rule->action_category_id)->toBe($groceries->id)
        ->and(onboardingStepFiled($user, 'MERCADONA EXPRESS'))->toBe(12)
        // The wider token was never created, so its movements are still waiting.
        ->and(onboardingStepFiled($user, 'MERCADONA'))->toBe(0);
});

// =============================================================================
// Step 9: filing movements by hand
// =============================================================================

/**
 * The step gates on movements actually filed, and skipping sends one to the
 * back of the queue rather than out of it — that is what stops a user from
 * skipping their way past the gate with nothing categorized.
 */
it('files the minimum by hand and keeps a skipped movement waiting', function () {
    $user = onboardingStepUser();
    $account = onboardingStepAccount($user);

    $groceries = Category::factory()->for($user)->create(['name' => 'Groceries', 'type' => 'expense']);
    Category::factory()->for($user)->create(['name' => 'Transport', 'type' => 'expense']);

    // Six movements, newest first, so the queue order is the order below. They
    // all sit inside last month, so a run on the 2nd of a month reads the same
    // month as a run on the 28th — and the step after this one has a month to
    // build its target on either way.
    $descriptions = [
        'Weekly groceries 42.10',
        'Coffee and a croissant 3.80',
        'Monthly travel pass 54.00',
        'Bookshop on the corner 18.95',
        'Pharmacy round the corner 9.40',
        'Hardware shop shelves 27.60',
    ];

    $lastMonth = now()->startOfMonth()->subMonth();

    foreach ($descriptions as $index => $description) {
        Transaction::factory()->for($user)->for($account)->create([
            'category_id' => null,
            'description' => $description,
            'creditor_name' => null,
            'amount' => -2200,
            'currency_code' => 'EUR',
            'transaction_date' => $lastMonth->copy()->addDays(20 - $index),
        ]);
    }

    actingAs($user);

    $page = visit('/onboarding?step=categorize-transactions');

    $page->assertSee('Teach us your habits')
        ->assertSee('File 5 movements to carry on')
        ->assertSee($descriptions[0])
        // Skipped, not dismissed: it goes to the back and the next one arrives.
        // The button carries its keyboard hint, so it is matched on the element
        // rather than on an exact label.
        ->click('button:has-text("Skip")')
        ->assertSee($descriptions[1])
        // The list takes input again only once the card behind it has settled,
        // so this is what says the step is ready for the next answer.
        ->assertEnabled('[placeholder="Search categories..."]');

    foreach ([1, 2, 3, 4, 5] as $position) {
        $page->click('Groceries');

        if ($position === 1) {
            // The rules hint opens over the list after the first one and holds
            // it disabled until it is acknowledged.
            $page->assertSee('Got it')->click('Got it');
        }

        // The movement behind the one just filed, which only arrives once the
        // card has swapped over. The fifth is the skipped one, come back round.
        $page->assertSee($descriptions[($position + 1) % 6])
            ->assertEnabled('[placeholder="Search categories..."]');
    }

    $page->assertSee('That’s enough to continue')
        ->click('Continue')
        ->assertSee('Your first target')
        ->assertNoJavascriptErrors();

    $filed = $user->transactions()->whereNotNull('category_id')->pluck('description');

    expect($filed)->toHaveCount(5)
        ->and($filed->all())->not->toContain($descriptions[0])
        ->and($user->transactions()->whereNull('category_id')->sole()->description)
        ->toBe($descriptions[0]);
});

// =============================================================================
// Step 10: the first target, and the screen that reads it back
// =============================================================================

/**
 * The target is the one number the user chooses rather than hands over, and the
 * only proof it was kept is the budget behind it and the line on the screen
 * after it.
 */
it('stores the target the stepper landed on and closes with it', function () {
    $user = onboardingStepUser(['goal' => 'understand', 'spending_guess' => 130000]);
    $account = onboardingStepAccount($user);

    // A month that comes to €1,000 across six movements, which is over the
    // minimum a month needs before it is read back at all.
    $lastMonth = now()->startOfMonth()->subMonth();

    foreach ([20000, 20000, 20000, 20000, 10000, 10000] as $index => $amount) {
        Transaction::factory()->for($user)->for($account)->create([
            'category_id' => null,
            'creditor_name' => sprintf('COMERCIO %02d', $index + 1),
            'amount' => -$amount,
            'currency_code' => 'EUR',
            'transaction_date' => $lastMonth->copy()->addDays($index + 1),
        ]);
    }

    actingAs($user);

    $page = visit('/onboarding?step=target');

    $page->assertSee('Your first target')
        // Built on the month that was read, against the month they guessed.
        ->assertSee('€1,000')
        ->assertSee('€1,300')
        // The stepper opens on a tenth of the month, which is €1,200 a year.
        ->assertSee('€1,200')
        ->click('[aria-label="Raise the target"]')
        ->assertSee('150')
        ->assertSee('€1,800')
        ->click('Set my target')
        ->assertSee('Your dashboard isn’t empty')
        ->assertSee('€150 a month put aside')
        ->assertNoJavascriptErrors();

    $budget = $user->budgets()->sole();

    expect($user->refresh()->onboarding_answers)->toMatchArray(['target' => 15000])
        ->and($budget->is_catch_all)->toBeTrue()
        // What is left of the month once the target is set aside.
        ->and($budget->getCurrentPeriod()->allocated_amount)->toBe(85000);
});

// =============================================================================
// The wait on a bank, and picking the flow back up
// =============================================================================

/**
 * The sync step is the only screen in the flow that moves on by itself. Every
 * test of it so far has caught it mid-wait; this one lets the bank finish.
 */
it('leaves the syncing step on its own once the bank is done', function () {
    $user = onboardingStepUser();

    $connection = BankingConnection::factory()->for($user)->create([
        'aspsp_name' => 'BBVA',
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => null,
    ]);

    $account = onboardingStepAccount($user, $connection);

    $lastMonth = now()->startOfMonth()->subMonth();

    foreach (['MERCADONA', 'MERCADONA', 'MERCADONA', 'REPSOL', 'REPSOL', 'ZARA'] as $index => $merchant) {
        Transaction::factory()->for($user)->for($account)->create([
            'category_id' => null,
            'creditor_name' => $merchant,
            'amount' => -21400,
            'currency_code' => 'EUR',
            'transaction_date' => $lastMonth->copy()->addDays($index + 1),
        ]);
    }

    actingAs($user);

    $page = visit('/onboarding?step=syncing');

    // The wait, counting what the bank has handed over so far.
    $page->assertSee('Reading your history')
        ->assertSee('BBVA')
        ->assertSee('Transactions read');

    // The queue finishes while the user is sitting on the screen.
    $connection->update(['last_synced_at' => now()]);

    // The poll picks it up and hands over to the reveal, with the month it just
    // finished reading in it.
    $page->assertSee('Last month')
        ->assertSee('1,284')
        ->assertSee('MERCADONA')
        ->assertNoJavascriptErrors();
});

/**
 * Everything the four questions collected is kept on the user's row as it is
 * answered, and the steps have to read it back — otherwise a user who comes
 * back is asked the same things again, or told they want to "undefined".
 */
it('reads the answers back to a user who comes back to them', function () {
    $user = onboardingStepUser([
        'goal' => 'understand',
        'today' => 'spreadsheet',
        'spending_guess' => 95000,
    ]);

    actingAs($user);

    $page = visit('/onboarding?step=plan');

    $page->assertSee("Here's what happens next")
        ->assertSee('understand where it all goes')
        ->assertSee('€950')
        // The header's back arrow, which no test has pressed.
        ->click('[aria-label="Back"]')
        ->assertSee('What did you spend last month?')
        // The slider opens on the guess that was locked in, not on the default.
        ->assertSee('950')
        ->click('[aria-label="Back"]')
        ->assertSee('How do you keep track today?')
        // The action is shut until a row is picked, so an enabled one is the
        // stored answer having been read back into the step.
        ->assertButtonEnabled('Continue')
        ->assertNoJavascriptErrors();
});

/**
 * A bank redirect that dies on iOS drops the user back on a bare /onboarding,
 * which used to restart them from the first screen with their accounts already
 * created. The step they left is remembered in the browser, and the URL is not
 * what carries it.
 */
it('resumes on the step the user left when nothing in the URL says so', function () {
    $user = onboardingStepUser();

    actingAs($user);

    $page = visit('/onboarding?step=goal');

    $page->assertSee('What do you want to change?')
        ->click('Understand where it all goes')
        ->click('Continue')
        ->assertSee('How do you keep track today?');

    // Back to the flow with nothing in the URL to go on. The step is not
    // written back into the query string on a resumed load — see the PR — so
    // what is asserted is the screen itself, and that it is not the first one.
    $page->navigate('/onboarding')
        ->assertSee('How do you keep track today?')
        ->assertDontSee('Find out where your money actually went')
        ->assertNoJavascriptErrors();

    expect($user->refresh()->onboarding_answers)->toMatchArray(['goal' => 'understand']);
});
