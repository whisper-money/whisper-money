<?php

use function Pest\Laravel\get;

it('serves the bare verification token when one is configured', function () {
    config(['services.openai.apps_challenge' => 'token-123']);

    get('/.well-known/openai-apps-challenge')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent('token-123');
});

it('404s when no verification token is configured', function () {
    config(['services.openai.apps_challenge' => null]);

    get('/.well-known/openai-apps-challenge')->assertNotFound();
});
