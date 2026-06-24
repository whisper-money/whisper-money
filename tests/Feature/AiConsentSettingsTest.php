<?php

use App\Features\AiConsentSettings;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

test('ai consent settings flag is off by default in shared props', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user)->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.aiConsentSettings', false)
        );
});

test('ai consent settings flag is exposed when activated for the user', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(AiConsentSettings::class);

    actingAs($user)->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.aiConsentSettings', true)
        );
});

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
