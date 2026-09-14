<?php

use App\Models\Bank;
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
        ->wait(1)
        ->assertSee("You're All Set!")
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
        ->assertSee("You're All Set!")
        ->assertMissing('[aria-label="Back"]')
        ->assertNoJavascriptErrors();
});
