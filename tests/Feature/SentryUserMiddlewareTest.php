<?php

use App\Http\Middleware\SetSentryUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\Scope;

it('sets sentry user context with id and email only', function () {
    SentrySdk::setCurrentHub(new Hub);

    $user = User::factory()->create([
        'name' => 'Secret Name',
        'email' => 'affected@example.com',
    ]);

    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => $user);

    (new SetSentryUser)->handle($request, fn () => new Response);

    SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($user): void {
        $sentryUser = $scope->getUser();

        expect($sentryUser)->not->toBeNull()
            ->and($sentryUser->getId())->toBe((string) $user->id)
            ->and($sentryUser->getEmail())->toBe('affected@example.com')
            ->and($sentryUser->getUsername())->toBeNull();
    });
});

it('leaves sentry user context empty for guests', function () {
    SentrySdk::setCurrentHub(new Hub);

    $request = Request::create('/');

    (new SetSentryUser)->handle($request, fn () => new Response);

    SentrySdk::getCurrentHub()->configureScope(function (Scope $scope): void {
        expect($scope->getUser())->toBeNull();
    });
});
