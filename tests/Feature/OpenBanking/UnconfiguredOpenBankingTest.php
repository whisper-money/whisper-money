<?php

use App\Enums\BankingProvider;
use App\Models\BankingConnection;
use App\Models\User;

/**
 * An install with no EnableBanking credentials — the self-hosted default.
 *
 * Nothing here may 500: the provider used to be built out of a null app id the
 * moment any open-banking route was touched, which took the first step of
 * onboarding down with it (#1037).
 */
beforeEach(function () {
    // The whole section blank, the way a docker-compose install ships. The
    // credentials matter as much as the flag: the container builds the provider
    // out of them, and that is where the 500 came from.
    config([
        'services.enablebanking.app_id' => null,
        'services.enablebanking.private_key_path' => null,
        'services.enablebanking.redirect_url' => null,
        'services.enablebanking.enabled' => false,
    ]);
});

test('the institutions endpoint answers with an empty catalogue', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->getJson('/open-banking/institutions?country=ES')
        ->assertOk()
        ->assertExactJson([]);
});

test('onboarding renders and says open banking is off', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    $this->actingAs($user)
        ->get('/onboarding?step=create-account')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('openBankingEnabled', false));
});

test('the routes only EnableBanking can serve are gone', function (string $method, string $uri) {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => BankingProvider::EnableBanking,
    ]);

    $this->actingAs($user)
        ->json($method, str_replace('{connection}', $connection->id, $uri))
        ->assertNotFound();
})->with([
    ['post', '/open-banking/authorize'],
    ['post', '/open-banking/connections/{connection}/reauthorize'],
    ['get', '/open-banking/connections/{connection}/reconnect'],
    ['get', '/open-banking/callback?code=test'],
]);

test('a broker connection is still reachable', function () {
    $user = User::factory()->onboarded()->create();

    // Only that the endpoint is served rather than swallowed by the guard: the
    // credentials are rubbish, so the connector rejects them on its own terms.
    $this->actingAs($user)
        ->postJson('/open-banking/indexa-capital/connect', ['token' => ''])
        ->assertUnprocessable();
});

test('a connection from another provider can still be managed', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => BankingProvider::Coinbase,
    ]);

    // Both resolve the banking provider out of the container even though a
    // Coinbase connection never calls it — which is what used to blow up.
    $this->actingAs($user)
        ->get("/open-banking/connections/{$connection->id}/accounts")
        ->assertSuccessful();

    $this->actingAs($user)
        ->delete("/settings/connections/{$connection->id}", ['delete_accounts' => false])
        ->assertRedirect(route('settings.connections.index'));

    expect(BankingConnection::withTrashed()->find($connection->id)->trashed())->toBeTrue();
});
