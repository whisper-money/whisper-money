<?php

use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * The landing pricing cards carry the plan the visitor picked into registration
 * (?plan=), and onboarding adapts to it. Every other entry point sends no plan
 * and must keep the original flow.
 */
function registerWith(array $overrides = []): void
{
    test()->post(route('register.store'), array_merge([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ], $overrides));
}

function registeredUser(): User
{
    return User::where('email', 'test@example.com')->firstOrFail();
}

beforeEach(function () {
    Queue::fake();
});

it('passes the plan from the register link to the register page', function (?string $query, ?string $expected) {
    $url = route('register').($query === null ? '' : "?plan={$query}");

    $this->get($url)
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('auth/register')
            ->where('signupPlan', $expected)
        );
})->with([
    'free card' => ['free', 'free'],
    'paid card' => ['paid', 'paid'],
    'garbage' => ['enterprise', null],
    'empty' => ['', null],
    'no plan at all' => [null, null],
]);

it('stores the plan the user registered from', function (?string $submitted, ?string $stored) {
    registerWith($submitted === null ? [] : ['signup_plan' => $submitted]);

    expect(registeredUser()->signup_plan)->toBe($stored);
})->with([
    'free card' => ['free', 'free'],
    'paid card' => ['paid', 'paid'],
    'garbage' => ['enterprise', null],
    'no plan at all' => [null, null],
]);

it('skips the end-of-onboarding paywall for a free signup', function () {
    registerWith(['signup_plan' => 'free']);

    expect(registeredUser()->hasSeenPaywall())->toBeTrue();
});

it('keeps the paywall for a paid signup and for every other entry point', function (?string $submitted) {
    registerWith($submitted === null ? [] : ['signup_plan' => $submitted]);

    expect(registeredUser()->hasSeenPaywall())->toBeFalse();
})->with([
    'paid card' => ['paid'],
    'no plan at all' => [null],
]);

it('sends a free signup to the dashboard instead of the paywall', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create(['signup_plan' => 'free', 'paywall_seen_at' => now()]);

    $this->actingAs($user)->get(route('dashboard'))->assertSuccessful();
});

it('exposes the signup plan to the onboarding page', function () {
    $user = User::factory()->create(['onboarded_at' => null, 'signup_plan' => 'paid']);

    $this->actingAs($user)->get('/onboarding')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('signupPlan', 'paid'));
});

it('refuses to deep link a free signup into the AI step', function () {
    $user = User::factory()->create(['onboarded_at' => null, 'signup_plan' => 'free']);

    $this->actingAs($user)->get('/onboarding?step=ai-suggestions')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('initialStep', null));
});

it('still allows the AI step deep link for every other signup', function (?string $plan) {
    $user = User::factory()->create(['onboarded_at' => null, 'signup_plan' => $plan]);

    $this->actingAs($user)->get('/onboarding?step=ai-suggestions')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('initialStep', 'ai-suggestions'));
})->with([
    'paid card' => ['paid'],
    'no plan at all' => [null],
]);
