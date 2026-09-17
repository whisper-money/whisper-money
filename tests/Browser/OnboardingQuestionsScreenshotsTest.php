<?php

use App\Models\User;

/**
 * The five screens this redesign adds, captured at phone width — they are the
 * flow's first impression, so the PR is reviewed on what they actually look
 * like rather than on the diff.
 */
it('captures the promise and the four questions', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'currency_code' => 'EUR',
    ]);

    $this->actingAs($user);

    visit('/onboarding')
        ->resize(430, 932)
        ->assertSee('Find out where your money actually went')
        ->screenshot(filename: 'onboarding-promise')
        ->click('Start')
        ->wait(1)
        ->assertSee('What do you want to change?')
        ->click('Understand where it all goes')
        ->wait(1)
        ->screenshot(filename: 'onboarding-goal')
        ->click('Continue')
        ->wait(1)
        ->assertSee('How do you keep track today?')
        ->click('In my head')
        ->wait(1)
        ->screenshot(filename: 'onboarding-today')
        ->click('Continue')
        ->wait(1)
        ->assertSee('What did you spend last month?')
        ->screenshot(filename: 'onboarding-guess')
        ->click('Lock in my guess')
        ->wait(1)
        ->assertSee("Here's what happens next")
        ->screenshot(filename: 'onboarding-plan')
        ->assertNoJavascriptErrors();
});
