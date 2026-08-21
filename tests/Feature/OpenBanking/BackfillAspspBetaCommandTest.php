<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingProvider;
use App\Models\BankingConnection;
use App\Models\User;

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

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    // One call per country, not per connection.
    $mockProvider->shouldReceive('getInstitutions')->with('ES')->once()->andReturn([
        ['name' => 'Openbank', 'beta' => true],
        ['name' => 'BBVA', 'beta' => false],
    ]);
    $mockProvider->shouldReceive('getInstitutions')->with('DE')->once()->andReturn([
        ['name' => 'N26', 'beta' => true],
    ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-aspsp-beta')
        ->expectsOutputToContain('Stamped 3 connection(s)')
        ->assertSuccessful();

    expect($openbank->refresh()->aspsp_beta)->toBeTrue()
        ->and($bbva->refresh()->aspsp_beta)->toBeFalse()
        ->and($n26->refresh()->aspsp_beta)->toBeTrue();
});

test('it leaves a connection alone when the catalogue no longer lists its bank', function () {
    $connection = BankingConnection::factory()->create([
        'provider' => BankingProvider::EnableBanking,
        'aspsp_name' => 'Bank That Left',
        'aspsp_country' => 'ES',
        'aspsp_beta' => null,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getInstitutions')->with('ES')->once()->andReturn([
        ['name' => 'BBVA', 'beta' => false],
    ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-aspsp-beta')
        ->expectsOutputToContain('Bank That Left (ES)')
        ->assertSuccessful();

    expect($connection->refresh()->aspsp_beta)->toBeNull();
});

test('a dry run reports without writing', function () {
    $connection = BankingConnection::factory()->create([
        'provider' => BankingProvider::EnableBanking,
        'aspsp_name' => 'Openbank',
        'aspsp_country' => 'ES',
        'aspsp_beta' => null,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getInstitutions')->with('ES')->once()->andReturn([
        ['name' => 'Openbank', 'beta' => true],
    ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-aspsp-beta', ['--dry-run' => true])
        ->expectsOutputToContain('Would stamp 1 connection(s)')
        ->assertSuccessful();

    expect($connection->refresh()->aspsp_beta)->toBeNull();
});

test('it skips connections that already know their flag and other providers', function () {
    BankingConnection::factory()->create([
        'provider' => BankingProvider::EnableBanking,
        'aspsp_name' => 'Openbank',
        'aspsp_country' => 'ES',
        'aspsp_beta' => false,
    ]);

    BankingConnection::factory()->wise()->create(['aspsp_beta' => null]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('getInstitutions');

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-aspsp-beta')
        ->expectsOutputToContain('Nothing to backfill.')
        ->assertSuccessful();
});

test('it fails without writing anything when the provider is down', function () {
    $connection = BankingConnection::factory()->create([
        'provider' => BankingProvider::EnableBanking,
        'aspsp_name' => 'Openbank',
        'aspsp_country' => 'ES',
        'aspsp_beta' => null,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getInstitutions')->with('ES')->once()->andThrow(new RuntimeException('502 Bad Gateway'));

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-aspsp-beta')->assertFailed();

    expect($connection->refresh()->aspsp_beta)->toBeNull();
});
