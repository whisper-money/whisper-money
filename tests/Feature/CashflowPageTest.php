<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

test('guests are redirected to registration', function () {
    $this->get(route('cashflow'))->assertRedirect(route('register'));
});

test('period prop is null when no query param given', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->get(route('cashflow'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('cashflow/index')
                ->where('period', null)
        );
});

test('valid period query param is passed to page props', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->get(route('cashflow', ['period' => '2025-03']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('cashflow/index')
                ->where('period', '2025-03')
        );
});

test('invalid period query param is sanitized to null', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->get(route('cashflow', ['period' => 'not-a-date']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('cashflow/index')
                ->where('period', null)
        );
});

test('malformed period format is rejected', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->get(route('cashflow', ['period' => '2025-3']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('cashflow/index')
                ->where('period', null)
        );
});
