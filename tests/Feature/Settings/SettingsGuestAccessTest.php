<?php

test('guests are sent to register from a settings endpoint', function (string $method, string $routeName, array $payload) {
    $response = $this->{$method}(route($routeName), $payload);

    $response->assertRedirect(route('register'));
})->with([
    'category list' => ['get', 'categories.index', []],
    'category creation' => ['post', 'categories.store', []],
    'chart color scheme' => ['patch', 'chart-color-scheme.update', ['chart_color_scheme' => 'blue']],
    'notification preferences' => ['patch', 'notifications.update', ['notifications' => ['bank_transactions_synced' => false]]],
    'net worth chart loan preference' => ['patch', 'net-worth-chart-loan-preference.update', ['include_loans_in_net_worth_chart' => false]],
    'net worth chart real estate preference' => ['patch', 'net-worth-chart-real-estate-preference.update', ['include_real_estate_in_net_worth_chart' => false]],
    'timezone' => ['patch', 'timezone.update', ['timezone' => 'Europe/Madrid']],
]);
