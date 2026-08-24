<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

test('billing page reports current ai consent state', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->onboarded()->create();

    actingAs($user)->withoutVite()->get(route('settings.billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/billing')
            ->where('hasAiConsent', false)
        );

    $user->recordAiConsent();

    actingAs($user)->withoutVite()->get(route('settings.billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasAiConsent', true)
        );
});

test('transactions page reports current ai consent state', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user)->withoutVite()->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('transactions/index')
            ->where('hasAiConsent', false)
        );

    $user->recordAiConsent();

    actingAs($user)->withoutVite()->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasAiConsent', true)
        );
});
