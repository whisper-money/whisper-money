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
    // The pricing section only renders when there is something to sell.
    config(['subscriptions.enabled' => true]);

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

it('plays the product video in the visitor language and color scheme', function (string $query, string $scheme, string $video) {
    $page = visit('/'.$query);

    if ($scheme === 'dark') {
        $page->inDarkMode();
    }

    $selector = '[data-slot="hero-video"] video';

    // Muted and inline are what let iOS Safari autoplay it at all.
    $page->assertPresent($selector)
        ->assertAttribute($selector, 'src', "/images/landing_videos/{$video}.mp4")
        ->assertAttribute($selector, 'poster', "/images/landing_videos/{$video}.jpg")
        ->assertScript("document.querySelector('{$selector}').muted", true)
        ->assertScript("document.querySelector('{$selector}').loop", true)
        ->assertScript("document.querySelector('{$selector}').playsInline", true)
        ->assertScript("document.querySelector('{$selector}').controls", false)
        ->assertScript("document.querySelectorAll('[data-slot=\"hero-video\"] video').length", 1)
        ->assertNoJavascriptErrors();
})->with([
    'english, light' => ['?lang=en', 'light', 'product-en-light'],
    'english, dark' => ['?lang=en', 'dark', 'product-en-dark'],
    'spanish, light' => ['?lang=es', 'light', 'product-es-light'],
    'spanish, dark' => ['?lang=es', 'dark', 'product-es-dark'],
    'french falls back to english' => ['?lang=fr', 'light', 'product-en-light'],
]);

it('swaps the video when the appearance changes', function () {
    $page = visit('/?lang=es');

    $selector = '[data-slot="hero-video"] video';

    $page->assertAttribute($selector, 'src', '/images/landing_videos/product-es-light.mp4');

    $page->script("document.documentElement.classList.add('dark')");

    $page->assertAttribute($selector, 'src', '/images/landing_videos/product-es-dark.mp4')
        ->assertScript("document.querySelectorAll('{$selector}').length", 1)
        ->assertNoJavascriptErrors();
});
