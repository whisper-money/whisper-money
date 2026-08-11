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
