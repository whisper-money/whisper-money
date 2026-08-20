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
        ->assertSee('Welcome to Whisper Money')
        ->click("Let's Get Started")
        ->wait(1)
        // Seven account-type rows push the content past the viewport: the
        // footer button has to stay clickable without scrolling.
        ->assertSee('Account Types')
        ->assertSee('Real Estate')
        ->click('Create Your First Account')
        ->wait(1)
        ->assertSee('How would you like to set up this account?')
        ->assertSee("You'll choose a plan at the end of the onboarding.")
        ->click('Manual')
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
        ->assertSee('No Uncategorized Transactions')
        ->click('Continue')
        ->wait(1)
        ->assertSee("You're All Set!")
        ->assertNoJavascriptErrors();
});

it('offers the header back arrow only where going back is safe', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $this->actingAs($user);

    // 'account-types' can be backed out of...
    visit('/onboarding?step=account-types')
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
