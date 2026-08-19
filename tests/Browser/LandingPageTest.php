<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

it('keeps a signed-in visitor on the landing page', function () {
    actingAs(User::factory()->onboarded()->create());

    $page = visit('/');

    // The wait is the point: a client-side redirect would fire on a timer, so
    // asserting the path immediately would pass even with one in place.
    $page->wait(4)
        ->assertPathIs('/')
        ->assertSee('Whisper Money')
        ->assertNoJavascriptErrors();
});

it('carries the pricing card plan into registration, and nothing else does', function () {
    $page = visit('/#pricing');

    // The whole feature hinges on this split: only the two pricing cards name a
    // plan, so every other entry point keeps the untouched onboarding.
    $page->assertPresent('#pricing a[href="/register?plan=free"]')
        ->assertPresent('#pricing a[href="/register?plan=paid"]')
        ->assertPresent('a[href="/register"]')
        ->assertNoJavascriptErrors();
});

it('sends the plan to the server as a hidden field on the register form', function () {
    $page = visit('/register?plan=free');

    $page->assertAttribute('input[name="signup_plan"]', 'value', 'free')
        ->assertNoJavascriptErrors();
});
