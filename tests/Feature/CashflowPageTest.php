<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('guests are redirected to registration', function () {
    $this->get(route('cashflow'))->assertRedirect(route('register'));
});

test('the period query params are sanitized into page props', function (array $query, ?string $expectedPeriod, string $expectedPeriodType) {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->get(route('cashflow', $query))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('cashflow/index')
                ->where('period', $expectedPeriod)
                ->where('periodType', $expectedPeriodType)
        );
})->with([
    'no query param' => [[], null, 'month'],
    'valid month' => [['period' => '2025-03'], '2025-03', 'month'],
    'valid quarter' => [['period_type' => 'quarter', 'period' => '2025-Q3'], '2025-Q3', 'quarter'],
    'valid year' => [['period_type' => 'year', 'period' => '2025'], '2025', 'year'],
    'invalid period' => [['period' => 'not-a-date'], null, 'month'],
    'malformed month format' => [['period' => '2025-3'], null, 'month'],
    'period not matching the period type' => [['period_type' => 'quarter', 'period' => '2025-03'], null, 'quarter'],
]);
