<?php

it('sends a guest to register from an onboarding step', function (string $method, string $path, array $payload) {
    $this->{$method}($path, $payload)->assertRedirect('/register');
})->with([
    'answers' => ['post', '/onboarding/answers', ['goal' => 'debt']],
    'currency' => ['patch', '/onboarding/currency', ['currency_code' => 'EUR']],
]);

it('answers a guest with 401 on an onboarding JSON endpoint', function (string $method, string $path, array $payload) {
    $this->{$method}($path, $payload)->assertUnauthorized();
})->with([
    'reveal' => ['getJson', '/onboarding/reveal', []],
    'summary' => ['getJson', '/onboarding/summary', []],
    'target' => ['postJson', '/onboarding/target', ['amount' => 20000, 'warn' => true]],
    'sync status' => ['getJson', '/onboarding/sync-status', []],
]);
