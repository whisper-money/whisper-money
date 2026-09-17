<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The file import at phone width, now that it is screens of the flow rather
 * than a drawer on top of it.
 *
 * The change is what these look like, so the PR is reviewed on them rather than
 * on the diff. The CSV in `assets/` is six months of movements with the totals
 * line every bank export leaves at the bottom, which is what the results screen
 * is written about.
 */
$movements = __DIR__.'/assets/onboarding-import-movements.csv';

function importUser(): User
{
    return User::factory()->notOnboarded()->create([
        'email_verified_at' => now(),
        'currency_code' => 'EUR',
        // The screenshots are read in English, and a Spanish number format on
        // an English screen is the test's own artefact, not the design's.
        'format_locale' => 'en-US',
    ]);
}

/** One account a file can go into, which is the case that asks nothing. */
function userWithOneAccount(): User
{
    $user = importUser();

    Account::factory()->for($user)->create([
        'name' => 'Everyday account',
        'type' => 'checking',
        'currency_code' => 'EUR',
    ]);

    return $user;
}

it('captures the question the drawer never asked', function () {
    $user = userWithOneAccount();

    Account::factory()->for($user)->create([
        'name' => 'Rainy day',
        'type' => 'savings',
        'currency_code' => 'EUR',
    ]);

    // A connected account is on the list to be ruled out, not to be picked.
    $connection = BankingConnection::factory()->for($user)->create();
    Account::factory()->for($user)->create([
        'name' => 'Cuenta Nómina',
        'type' => 'checking',
        'currency_code' => 'EUR',
        'banking_connection_id' => $connection->id,
    ]);

    actingAs($user);

    visit('/onboarding?step=import-transactions')
        ->resize(430, 932)
        ->waitForText('Which account is this file from?', 10)
        ->assertSee('Everyday account')
        ->assertSee('syncing from the bank')
        ->assertSee('you end up with everything twice')
        ->wait(1)
        ->screenshot(filename: 'import-pick-account')
        ->assertNoJavascriptErrors();
});

it('captures the file screen, and what it promises about the file', function () {
    actingAs(userWithOneAccount());

    visit('/onboarding?step=import-transactions')
        ->resize(430, 932)
        ->waitForText('Bring in your history', 10)
        ->assertSee('Drop the file here')
        ->assertSee('in your browser')
        // The way past the step for someone with no file to hand.
        ->assertSee("I don't have one yet")
        ->wait(1)
        ->screenshot(filename: 'import-upload')
        ->assertNoJavascriptErrors();
});

it('captures the columns we guessed, for the user to disagree with', function () use ($movements) {
    actingAs(userWithOneAccount());

    visit('/onboarding?step=import-transactions')
        ->resize(430, 932)
        ->waitForText('Bring in your history', 10)
        ->attach('input[type="file"]', $movements)
        ->waitForText('Did we read it right?', 10)
        ->assertSee('Fecha valor')
        ->assertSee('Concepto')
        ->assertSee('Importe')
        ->wait(1)
        ->screenshot(filename: 'import-columns')
        ->assertNoJavascriptErrors();
});

it('captures the movements before a single one is saved', function () use ($movements) {
    actingAs(userWithOneAccount());

    visit('/onboarding?step=import-transactions')
        ->resize(430, 932)
        ->waitForText('Bring in your history', 10)
        ->attach('input[type="file"]', $movements)
        ->waitForText('Did we read it right?', 10)
        ->click("That's right")
        ->waitForText('312 movements', 10)
        ->assertSee('Nothing is saved until you say the word')
        ->assertSee('Import 312 movements')
        ->wait(1)
        ->screenshot(filename: 'import-preview')
        ->assertNoJavascriptErrors();
});

it('captures the progress, and the results the file left behind', function () use ($movements) {
    actingAs(userWithOneAccount());

    $page = visit('/onboarding?step=import-transactions')
        ->resize(430, 932)
        ->waitForText('Bring in your history', 10)
        ->attach('input[type="file"]', $movements)
        ->waitForText('Did we read it right?', 10)
        ->click("That's right")
        ->waitForText('Import 312 movements', 10)
        ->click('Import 312 movements');

    // Progress is reported a batch at a time, so a batch boundary is a state
    // the screen actually passes through rather than a moment to race for.
    $page->waitForText('Bringing them in', 10)
        ->waitForText('60 of 312', 30)
        ->screenshot(filename: 'import-progress');

    // The two rows at the bottom of the file have no date, so they never
    // reached the import — and the user would otherwise never hear about them.
    $page->waitForText("we couldn't read", 60)
        ->assertSee('What went wrong')
        ->assertSee('2 rows')
        ->assertSee('Continue with 312')
        ->wait(1)
        ->screenshot(filename: 'import-partial')
        ->assertNoJavascriptErrors();
});

it('captures the PDF every bank offers first', function () {
    actingAs(userWithOneAccount());

    visit('/onboarding?step=import-transactions')
        ->resize(430, 932)
        ->waitForText('Bring in your history', 10)
        ->attach('input[type="file"]', __DIR__.'/assets/onboarding-import-statement.pdf')
        ->waitForText("We can't read that one", 10)
        ->assertSee('PDF is not supported')
        ->assertSee('Connect the bank instead')
        ->wait(1)
        ->screenshot(filename: 'import-upload-error')
        ->assertNoJavascriptErrors();
});
