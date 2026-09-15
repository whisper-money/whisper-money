<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\Transaction;
use App\Models\User;

/**
 * The onboarding is a phone-first flow: the action lives in a footer pinned to
 * the bottom of the viewport, which the desktop-width tests never exercise
 * (`md:static` unpins it). These walk it at phone width so a footer that
 * covered or intercepted its own button would fail here.
 */
beforeEach(function () {
    config(['subscriptions.enabled' => true]);
});

it('walks the first steps on a phone with the action pinned to the footer', function () {
    Bank::factory()->create(['name' => 'Phone Bank']);

    $user = User::factory()->create(['onboarded_at' => null]);
    $this->actingAs($user);

    visit('/onboarding')
        ->resize(430, 932)
        ->assertSee('Find out where your money actually went')
        ->click('Start')
        ->wait(1)
        // The four rows plus the copy push the content past the viewport: the
        // footer button has to stay clickable without scrolling.
        ->assertSee('What do you want to change?')
        ->assertSee('Save for something specific')
        ->click('Understand where it all goes')
        ->wait(1)
        ->click('Continue')
        ->wait(1)
        ->assertSee('How do you keep track today?')
        ->click('In my head')
        ->wait(1)
        ->click('Continue')
        ->wait(1)
        // The slider and the note below it are the tallest question of the four.
        ->assertSee('What did you spend last month?')
        ->click('Lock in my guess')
        ->wait(1)
        ->assertSee("Here's what happens next")
        ->click("Let's go")
        ->wait(1)
        ->assertSee("Let's build the picture")
        ->assertSee("You'll choose a plan at the end of the onboarding.")
        ->click('Add one myself')
        ->wait(1)
        ->assertSee('Create an Account')
        ->assertNoJavascriptErrors();
});

it('categorizes on a phone with both footer actions reachable', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $this->actingAs($user);

    visit('/onboarding?step=categorize-transactions')
        ->resize(430, 932)
        ->wait(2)
        ->assertSee('Nothing left to teach us')
        ->click('Continue')
        ->wait(2)
        // No month was read, so the target step has nothing to build on and
        // steps aside: the flow lands on the close itself.
        ->assertSee('Your dashboard isn’t empty')
        ->assertNoJavascriptErrors();
});

it('offers the header back arrow only where going back is safe', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $this->actingAs($user);

    // 'goal' can be backed out of...
    visit('/onboarding?step=goal')
        ->resize(430, 932)
        ->wait(1)
        ->assertPresent('[aria-label="Back"]')
        ->assertNoJavascriptErrors();

    // ...but the terminal step must not offer it.
    visit('/onboarding?step=complete')
        ->resize(430, 932)
        ->wait(1)
        ->assertSee('Your dashboard isn’t empty')
        ->assertMissing('[aria-label="Back"]')
        ->assertNoJavascriptErrors();
});

/**
 * The footer is opaque and pinned to the bottom of the viewport for the whole
 * scroll, so anything under it is readable only at the very end of it.
 *
 * Measured at 390px on the AI gate: the row arguing for the free path sat at
 * 686–706 under a footer starting at 676, with 58px of scroll to its name — it
 * cleared by 16px at maximum scroll and by nothing at all at rest, on the screen
 * that asks for money. The floor below is the room that grazing left out.
 */
it('lets the last line clear the pinned footer at 390px', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create([
        'onboarded_at' => null,
        'format_locale' => 'en-US',
    ]);
    $account = Account::factory()->for($user)->create(['type' => 'checking', 'currency_code' => 'EUR']);

    // The same 903 the gate screenshots use: the count is in the screen's own
    // description, so it is what makes the page as tall as it was measured at.
    Transaction::factory()
        ->count(903)
        ->for($user)
        ->for($account)
        ->plaintext()
        ->sequence(fn ($sequence): array => [
            'creditor_name' => sprintf('COMERCIO %03d', $sequence->index % 40),
        ])
        ->create([
            'category_id' => null,
            'amount' => -2200,
            'currency_code' => 'EUR',
            'transaction_date' => now()->subDays(20),
        ]);

    $this->actingAs($user);

    $page = visit('/onboarding?step=ai-suggestions')
        ->resize(390, 844)
        ->waitForText('Or keep sorting by hand', 15);

    $overlap = (int) $page->script(<<<'JS'
        (async () => {
            window.scrollTo(0, document.documentElement.scrollHeight);
            await new Promise((resolve) => setTimeout(resolve, 300));

            const label = 'Free, unlimited, and your rules still work';
            const last = Array.from(document.querySelectorAll('*'))
                .filter((el) => el.children.length === 0 && el.textContent.trim() === label)
                .pop();
            const footer = document.querySelector('main .sticky');

            return Math.round(
                last.getBoundingClientRect().bottom - footer.getBoundingClientRect().top,
            );
        })()
    JS);

    expect($overlap)->toBeLessThanOrEqual(-24);

    $page->assertNoJavascriptErrors();
});
