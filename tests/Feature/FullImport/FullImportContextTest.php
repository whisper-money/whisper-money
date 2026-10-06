<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\User;
use Tests\Support\FullImportFixtures as Fixtures;

it('matches account names to banks by name, case and accents aside, without creating any', function () {
    $user = Fixtures::user();
    $wise = Bank::factory()->create(['name' => 'Wise', 'user_id' => null]);
    $myInvestor = Bank::factory()->create(['name' => 'MyInvestor', 'user_id' => null]);
    $caixa = Bank::factory()->create(['name' => 'Caixa Bank Ñ', 'user_id' => $user->id]);
    Bank::factory()->create(['name' => 'Private Bank', 'user_id' => User::factory()->create()->id]);
    $banksBefore = Bank::query()->count();

    $this->actingAs($user)
        ->postJson(route('api.full-imports.bank-matches'), ['names' => ['Wise Personal', 'myinvestor', 'CAIXA BANK N', 'Private Bank', 'Cash']])
        ->assertOk()
        ->assertJsonPath('matches.Wise Personal.id', $wise->id)
        ->assertJsonPath('matches.myinvestor.id', $myInvestor->id)
        ->assertJsonPath('matches.CAIXA BANK N.id', $caixa->id)
        ->assertJsonPath('matches.Private Bank', null)
        ->assertJsonPath('matches.Cash', null);

    expect(Bank::query()->count())->toBe($banksBefore);
});

it('gives the wizard the space it imports into', function () {
    $user = Fixtures::user();
    $manual = Account::factory()->create(['user_id' => $user->id, 'name' => 'BBVA', 'type' => AccountType::Checking]);
    $connected = Account::factory()->connected()->create(['user_id' => $user->id, 'name' => 'Revolut', 'type' => AccountType::Checking]);
    Account::factory()->loan()->create(['user_id' => $user->id, 'name' => 'Mortgage']);

    $response = $this->actingAs($user)->getJson(route('api.full-imports.context'))->assertOk();

    expect($response->json('mappableAccountIds'))->toBe([$manual->id])
        ->and(collect($response->json('accounts'))->firstWhere('id', $connected->id)['connected'])->toBeTrue()
        ->and($response->json('transferTargets.own.category_id'))->toBe(Fixtures::seeded($user, 'Own account')->id)
        ->and($response->json('transferTargets.ignored.category_id'))->toBe(Fixtures::seeded($user, 'Other transfers')->id)
        ->and($response->json('defaultCategoryNames'))->toContain(['en' => 'Insurance', 'es' => 'Seguros'])
        ->and($response->json('profiles'))->toBe(['banktrack' => null, 'generic' => null]);
});

it('offers what to create when the seeded transfer categories are gone', function () {
    $user = User::factory()->onboarded()->create(['locale' => 'es']);

    $this->actingAs($user)->getJson(route('api.full-imports.context'))
        ->assertJsonPath('transferTargets.own', ['category_id' => null, 'name' => 'Cuenta propia', 'icon' => 'ArrowRightLeft', 'color' => 'blue'])
        ->assertJsonPath('transferTargets.ignored.name', 'Otras transferencias');
});

it('remembers the column layout of the last import of each source', function () {
    $user = Fixtures::user();

    Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]);

    $this->getJson(route('api.full-imports.context'))
        ->assertJsonPath('profiles.banktrack.columns.date', 'Fecha')
        ->assertJsonPath('profiles.banktrack.date_format', 'DD-MM-YYYY')
        ->assertJsonPath('profiles.generic', null);
});
