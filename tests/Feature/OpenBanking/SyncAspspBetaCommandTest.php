<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingProvider;
use App\Models\BankingConnection;
use App\Models\User;

/**
 * @param  array<string, bool>  $flagsByCountry  Country to [bank name => beta].
 */
function fakeCatalogue(array $flagsByCountry): Mockery\MockInterface
{
    $provider = Mockery::mock(BankingProviderInterface::class);

    foreach ($flagsByCountry as $country => $banks) {
        $provider->shouldReceive('getInstitutions')
            ->with($country)
            ->once()
            ->andReturn(array_map(
                fn (string $name, bool $beta): array => ['name' => $name, 'beta' => $beta],
                array_keys($banks),
                array_values($banks),
            ));
    }

    app()->instance(BankingProviderInterface::class, $provider);

    return $provider;
}

function enableBankingConnection(string $name, string $country = 'ES', ?bool $beta = null): BankingConnection
{
    return BankingConnection::factory()->create([
        'provider' => BankingProvider::EnableBanking,
        'aspsp_name' => $name,
        'aspsp_country' => $country,
        'aspsp_beta' => $beta,
    ]);
}

test('it stamps the provider beta flag on connections that predate the column', function () {
    $user = User::factory()->create();

    $openbank = BankingConnection::factory()->for($user)->create([
        'provider' => BankingProvider::EnableBanking,
        'aspsp_name' => 'Openbank',
        'aspsp_country' => 'ES',
        'aspsp_beta' => null,
    ]);

    $bbva = BankingConnection::factory()->for($user)->create([
        'provider' => BankingProvider::EnableBanking,
        'aspsp_name' => 'BBVA',
        'aspsp_country' => 'ES',
        'aspsp_beta' => null,
    ]);

    $n26 = BankingConnection::factory()->for($user)->create([
        'provider' => BankingProvider::EnableBanking,
        'aspsp_name' => 'N26',
        'aspsp_country' => 'DE',
        'aspsp_beta' => null,
    ]);

    // One call per country, not per connection.
    fakeCatalogue([
        'ES' => ['Openbank' => true, 'BBVA' => false],
        'DE' => ['N26' => true],
    ]);

    $this->artisan('banking:sync-aspsp-beta')
        ->expectsOutputToContain('3 stamped for the first time')
        ->assertSuccessful();

    expect($openbank->refresh()->aspsp_beta)->toBeTrue()
        ->and($bbva->refresh()->aspsp_beta)->toBeFalse()
        ->and($n26->refresh()->aspsp_beta)->toBeTrue();
});

test('it clears the flag once the provider stops calling a connector beta', function () {
    $stabilised = enableBankingConnection('Openbank', beta: true);

    fakeCatalogue(['ES' => ['Openbank' => false]]);

    $this->artisan('banking:sync-aspsp-beta')
        ->expectsOutputToContain('1 left beta')
        ->assertSuccessful();

    expect($stabilised->refresh()->aspsp_beta)->toBeFalse();
});

test('it sets the flag when a connector the user is already on regresses into beta', function () {
    $regressed = enableBankingConnection('Openbank', beta: false);

    fakeCatalogue(['ES' => ['Openbank' => true]]);

    $this->artisan('banking:sync-aspsp-beta')
        ->expectsOutputToContain('1 entered beta')
        ->assertSuccessful();

    expect($regressed->refresh()->aspsp_beta)->toBeTrue();
});

test('a connection whose flag already agrees with the catalogue is left untouched', function () {
    $agreed = enableBankingConnection('Openbank', beta: true);
    $updatedAt = $agreed->updated_at;

    fakeCatalogue(['ES' => ['Openbank' => true]]);

    $this->artisan('banking:sync-aspsp-beta')
        ->expectsOutputToContain('0 stamped for the first time, 0 entered beta, 0 left beta')
        ->assertSuccessful();

    // No write at all, so not even the timestamp moves.
    expect($agreed->refresh()->aspsp_beta)->toBeTrue()
        ->and($agreed->updated_at->eq($updatedAt))->toBeTrue();
});

test('a bank that has left the catalogue keeps the flag we last knew', function () {
    // Losing the badge would be worse than showing a stale one: no answer
    // renders nothing at all, so the user stops being warned.
    $delisted = enableBankingConnection('Bank That Left', beta: true);

    fakeCatalogue(['ES' => ['BBVA' => false]]);

    $this->artisan('banking:sync-aspsp-beta')
        ->expectsOutputToContain('Bank That Left (ES)')
        ->assertSuccessful();

    expect($delisted->refresh()->aspsp_beta)->toBeTrue();
});

test('a dry run reports without writing', function () {
    $connection = enableBankingConnection('Openbank', beta: null);

    fakeCatalogue(['ES' => ['Openbank' => true]]);

    $this->artisan('banking:sync-aspsp-beta', ['--dry-run' => true])
        ->expectsOutputToContain('Would change:')
        ->assertSuccessful();

    expect($connection->refresh()->aspsp_beta)->toBeNull();
});

test('it ignores providers that have no notion of a beta connector', function () {
    BankingConnection::factory()->wise()->create(['aspsp_beta' => null]);

    $provider = Mockery::mock(BankingProviderInterface::class);
    $provider->shouldNotReceive('getInstitutions');
    $this->app->instance(BankingProviderInterface::class, $provider);

    $this->artisan('banking:sync-aspsp-beta')
        ->expectsOutputToContain('No EnableBanking connections to check.')
        ->assertSuccessful();
});

test('one country failing does not stop the others, and the run still fails', function () {
    $spanish = enableBankingConnection('Openbank', 'ES', beta: null);
    $german = enableBankingConnection('N26', 'DE', beta: null);

    $provider = Mockery::mock(BankingProviderInterface::class);
    $provider->shouldReceive('getInstitutions')->with('ES')->once()
        ->andThrow(new RuntimeException('502 Bad Gateway'));
    $provider->shouldReceive('getInstitutions')->with('DE')->once()
        ->andReturn([['name' => 'N26', 'beta' => true]]);

    $this->app->instance(BankingProviderInterface::class, $provider);

    $this->artisan('banking:sync-aspsp-beta')
        ->expectsOutputToContain('Could not check: ES.')
        ->assertFailed();

    expect($german->refresh()->aspsp_beta)->toBeTrue()
        ->and($spanish->refresh()->aspsp_beta)->toBeNull();
});
