<?php

use App\Contracts\BankingProviderInterface;
use App\Models\User;

test('authenticated users can list institutions', function () {
    $user = User::factory()->onboarded()->create();

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getInstitutions')
        ->with('ES')
        ->once()
        ->andReturn([
            ['name' => 'Test Bank', 'country' => 'ES', 'logo' => 'https://example.com/logo.png', 'maximum_consent_validity' => 90],
            ['name' => 'Another Bank', 'country' => 'ES', 'logo' => null, 'maximum_consent_validity' => 180],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->getJson('/open-banking/institutions?country=ES');

    $response->assertOk();
    $response->assertJsonCount(2);
    $response->assertJsonFragment(['name' => 'Test Bank']);
});

test('institutions endpoint requires country parameter', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->getJson('/open-banking/institutions');

    $response->assertUnprocessable();
});

test('institutions endpoint requires valid country code length', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->getJson('/open-banking/institutions?country=SPAIN');

    $response->assertUnprocessable();
});

test('guests cannot access institutions endpoint', function () {
    $response = $this->getJson('/open-banking/institutions?country=ES');

    $response->assertUnauthorized();
});
