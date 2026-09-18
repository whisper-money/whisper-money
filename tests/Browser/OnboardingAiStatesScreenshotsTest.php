<?php

declare(strict_types=1);

use App\Enums\SuggestionRunStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\RuleSuggestion;
use App\Models\SuggestionRun;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Step 8 at phone width, in each of the six states it can land in, plus the
 * step that follows it.
 *
 * Five of the six are the states nobody designs for — too little history, a run
 * that died, a year with no repeat merchant, a consent that was revoked — and
 * they are the reason this PR exists, so it is reviewed on these rather than on
 * the diff.
 */
function aiUser(): User
{
    return User::factory()->notOnboarded()->create([
        'email_verified_at' => now(),
        'currency_code' => 'EUR',
        // The screenshots are read in English, and a Spanish number format on
        // an English screen is the test's own artefact, not the design's.
        'format_locale' => 'en-US',
    ]);
}

function aiAccount(User $user): Account
{
    return Account::factory()->for($user)->create([
        'name' => 'Cuenta Nómina',
        'type' => 'checking',
        'currency_code' => 'EUR',
    ]);
}

/** The same merchant, over and over, which is what a rule is made of. */
function merchant(Account $account, string $name, int $times, int $amount): void
{
    Transaction::factory()
        ->count($times)
        ->for($account->user)
        ->for($account)
        ->sequence(fn ($sequence): array => [
            'description' => sprintf('%s %04d', $name, $sequence->index + 1),
        ])
        ->create([
            'category_id' => null,
            'creditor_name' => $name,
            'amount' => -$amount,
            'currency_code' => 'EUR',
            'transaction_date' => now()->subDays(30),
        ]);
}

/** The long tail: one movement each, no pattern to find in any of them. */
function oneOffs(Account $account, int $count): void
{
    Transaction::factory()
        ->count($count)
        ->for($account->user)
        ->for($account)
        ->sequence(fn ($sequence): array => [
            'description' => sprintf('COMERCIO %03d', $sequence->index + 1),
            'creditor_name' => sprintf('COMERCIO %03d', $sequence->index + 1),
            'amount' => -1000 - $sequence->index,
        ])
        ->create([
            'category_id' => null,
            'currency_code' => 'EUR',
            'transaction_date' => now()->subDays(60),
        ]);
}

/** A year of movements that adds up to 903, four merchants of which repeat. */
function aYearOfMovements(Account $account): void
{
    merchant($account, 'MERCADONA', 84, 6200);
    merchant($account, 'GLOVO', 31, 2220);
    merchant($account, 'AMAZON', 47, 2380);
    merchant($account, 'NETFLIX', 12, 1290);
    oneOffs($account, 729);
}

it('captures the run counting merchants out loud', function () {
    $user = aiUser();
    $user->recordAiConsent();
    aYearOfMovements(aiAccount($user));

    // A run that is still going: the client polls it and shows what it has
    // reached, which is the whole point of the screen.
    SuggestionRun::factory()->for($user)->create([
        'status' => SuggestionRunStatus::Processing,
        'merchants_considered' => 147,
        'suggestions_count' => 23,
    ]);

    actingAs($user);

    visit('/onboarding?step=ai-suggestions')
        ->resize(430, 932)
        ->waitForText('Reading your merchants', 10)
        ->assertSee('903 movements')
        ->assertSee('Merchants found')
        ->assertSee('147')
        ->assertSee('Rules drafted')
        ->assertSee('Up to a couple of minutes. You can leave this screen — it keeps going without you.')
        ->wait(1)
        ->screenshot(filename: 'ai-generating')
        ->assertNoJavascriptErrors();
});

it('captures the rules the run came back with', function () {
    $user = aiUser();
    $user->recordAiConsent();
    $account = aiAccount($user);
    aYearOfMovements($account);

    $groceries = Category::factory()->for($user)->create(['name' => 'Groceries', 'type' => 'expense']);
    $eatingOut = Category::factory()->for($user)->create(['name' => 'Eating out', 'type' => 'expense']);
    $shopping = Category::factory()->for($user)->create(['name' => 'Shopping', 'type' => 'expense']);
    $subscriptions = Category::factory()->for($user)->create(['name' => 'Subscriptions', 'type' => 'expense']);

    $run = SuggestionRun::factory()->for($user)->create([
        'status' => SuggestionRunStatus::Completed,
        'merchants_considered' => 147,
        'suggestions_count' => 4,
    ]);

    // AMAZON comes back under the auto-select bar: shown, but left unticked for
    // the user to opt into, which is the row the design calls "we're less sure".
    $rules = [
        ['MERCADONA', $groceries->id, 0.97],
        ['GLOVO', $eatingOut->id, 0.93],
        ['NETFLIX', $subscriptions->id, 0.91],
        ['AMAZON', $shopping->id, 0.45],
    ];

    foreach ($rules as [$token, $categoryId, $confidence]) {
        RuleSuggestion::factory()->for($run, 'run')->create([
            'match_field' => 'creditor_name',
            'match_operator' => 'equals',
            'match_token' => mb_strtolower($token),
            'group_key' => mb_strtolower($token),
            'proposed_category_id' => $categoryId,
            'confidence' => $confidence,
            'sample_descriptions' => [$token.' 0001'],
        ]);
    }

    actingAs($user);

    visit('/onboarding?step=ai-suggestions')
        ->resize(430, 932)
        ->waitForText('ready when you are', 10)
        ->assertSee('Groceries')
        ->assertSee('Any of them can be undone from the transaction itself.')
        ->wait(1)
        ->screenshot(filename: 'ai-review')
        ->assertNoJavascriptErrors();
});

it('captures what a short file gets instead', function () {
    $user = aiUser();
    $user->recordAiConsent();
    oneOffs(aiAccount($user), 31);

    actingAs($user);

    visit('/onboarding?step=ai-suggestions')
        ->resize(430, 932)
        ->waitForText('Not enough to learn from yet', 10)
        ->assertSee('You have 31 movements')
        ->assertSee('Bring more history')
        ->assertSee('Add more history first')
        ->wait(1)
        ->screenshot(filename: 'ai-not-eligible')
        ->assertNoJavascriptErrors();
});

it('captures a run that died before it finished', function () {
    $user = aiUser();
    $user->recordAiConsent();
    aYearOfMovements(aiAccount($user));

    SuggestionRun::factory()->for($user)->create([
        'status' => SuggestionRunStatus::Failed,
        'merchants_considered' => 147,
        'error' => 'The model timed out',
    ]);

    actingAs($user);

    visit('/onboarding?step=ai-suggestions')
        ->resize(430, 932)
        ->waitForText('That didn’t finish', 10)
        ->assertSee('903 movements, at your own pace, whenever')
        ->assertSee('Try again')
        ->wait(1)
        ->screenshot(filename: 'ai-failed')
        ->assertNoJavascriptErrors();
});

it('captures a year with no merchant worth a rule', function () {
    $user = aiUser();
    $user->recordAiConsent();
    oneOffs(aiAccount($user), 903);

    SuggestionRun::factory()->for($user)->create([
        'status' => SuggestionRunStatus::Empty,
        'merchants_considered' => 903,
        'suggestions_count' => 0,
    ]);

    actingAs($user);

    visit('/onboarding?step=ai-suggestions')
        ->resize(430, 932)
        ->waitForText('Nothing worth a rule', 10)
        ->assertSee('We read all 903')
        // The claim the design made here — "847 of them got a category" — is
        // not true at this point in the flow, and is gone.
        ->assertSee('Nothing was changed')
        ->wait(1)
        ->screenshot(filename: 'ai-empty')
        ->assertNoJavascriptErrors();
});

it('captures the consent screen a returning user gets', function () {
    $user = aiUser();
    aYearOfMovements(aiAccount($user));

    // Consented once, then switched it off: the screen has to say why it is
    // asking a second time.
    $user->recordAiConsent();
    $user->revokeAiConsent();

    actingAs($user);

    visit('/onboarding?step=ai-suggestions')
        ->resize(430, 932)
        ->waitForText('Turn AI sorting back on?', 10)
        ->assertSee('One line at a time, never the picture')
        ->assertSee('903 movements')
        ->assertSee('Leave it off')
        ->wait(1)
        ->screenshot(filename: 'ai-re-consent')
        ->assertNoJavascriptErrors();
});

it('captures the movements the rules could not place', function () {
    $user = aiUser();
    $account = aiAccount($user);

    merchant($account, 'GLOVO ES BARCELONA', 6, 2480);
    oneOffs($account, 20);

    Category::factory()->for($user)->create(['name' => 'Eating out', 'type' => 'expense']);
    Category::factory()->for($user)->create(['name' => 'Groceries', 'type' => 'expense']);
    Category::factory()->for($user)->create(['name' => 'Transport', 'type' => 'expense']);

    actingAs($user);

    visit('/onboarding?step=categorize-transactions')
        ->resize(430, 932)
        ->waitForText('Teach us your habits', 10)
        ->assertSee('File 5 movements to carry on')
        ->assertSee('Not sure about one? Skip it and we’ll bring you another.')
        ->wait(1)
        ->screenshot(filename: 'teach-us')
        ->assertNoJavascriptErrors();
});
