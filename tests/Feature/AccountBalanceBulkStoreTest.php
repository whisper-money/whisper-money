<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Import;
use App\Models\User;

use function Pest\Laravel\actingAs;

function bulkBalancesUrl(Account $account): string
{
    return route('api.accounts.balances.store-many', $account);
}

it('writes every balance in one request', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['type' => AccountType::Checking]);

    actingAs($user)->postJson(bulkBalancesUrl($account), [
        'balances' => [
            ['balance_date' => '2026-01-01', 'balance' => 1000],
            ['balance_date' => '2026-01-02', 'balance' => 1500],
            ['balance_date' => '2026-01-03', 'balance' => -200],
        ],
    ])->assertCreated()->assertJson(['written' => 3]);

    expect($account->balances()->orderBy('balance_date')->pluck('balance')->all())->toBe([1000, 1500, -200])
        ->and($account->balances()->where('derived', true)->exists())->toBeFalse();
});

it('replaces the balances already on those days, so a second run changes nothing', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['type' => AccountType::Investment]);
    AccountBalance::factory()->for($account)->create([
        'balance_date' => '2026-01-01',
        'balance' => 1,
        'invested_amount' => 1,
        'derived' => true,
    ]);

    $payload = ['balances' => [
        ['balance_date' => '2026-01-01', 'balance' => 1000, 'invested_amount' => 900],
        ['balance_date' => '2026-01-02', 'balance' => 1100, 'invested_amount' => 900],
    ]];

    actingAs($user)->postJson(bulkBalancesUrl($account), $payload)->assertCreated();
    actingAs($user)->postJson(bulkBalancesUrl($account), $payload)->assertCreated();

    expect($account->balances()->count())->toBe(2);

    $first = $account->balances()->whereDate('balance_date', '2026-01-01')->firstOrFail();
    expect($first->balance)->toBe(1000)
        ->and($first->invested_amount)->toBe(900)
        ->and($first->derived)->toBeFalse();
});

it('keeps the last balance when a day comes twice', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['type' => AccountType::Checking]);

    actingAs($user)->postJson(bulkBalancesUrl($account), [
        'balances' => [
            ['balance_date' => '2026-01-01', 'balance' => 1000],
            ['balance_date' => '2026-01-01', 'balance' => 2000],
        ],
    ])->assertCreated()->assertJson(['written' => 1]);

    expect($account->balances()->sole()->balance)->toBe(2000);
});

it('stores the invested amount on account types that track it', function (AccountType $type) {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['type' => $type]);

    actingAs($user)->postJson(bulkBalancesUrl($account), [
        'balances' => [['balance_date' => '2026-01-01', 'balance' => 1000, 'invested_amount' => 800]],
    ])->assertCreated();

    expect($account->balances()->sole()->invested_amount)->toBe(800);
})->with([AccountType::Investment, AccountType::Retirement, AccountType::Savings]);

it('drops the invested amount on account types that do not track it', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['type' => AccountType::Checking]);

    actingAs($user)->postJson(bulkBalancesUrl($account), [
        'balances' => [['balance_date' => '2026-01-01', 'balance' => 1000, 'invested_amount' => 800]],
    ])->assertCreated();

    expect($account->balances()->sole()->invested_amount)->toBeNull();
});

it('leaves the stored invested amount alone when a row carries none', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['type' => AccountType::Investment]);
    AccountBalance::factory()->for($account)->create([
        'balance_date' => '2026-01-01',
        'balance' => 1,
        'invested_amount' => 700,
    ]);

    actingAs($user)->postJson(bulkBalancesUrl($account), [
        'balances' => [
            ['balance_date' => '2026-01-01', 'balance' => 1000, 'invested_amount' => null],
            ['balance_date' => '2026-01-02', 'balance' => 1100],
        ],
    ])->assertCreated();

    expect($account->balances()->whereDate('balance_date', '2026-01-01')->sole())
        ->balance->toBe(1000)
        ->invested_amount->toBe(700)
        ->and($account->balances()->whereDate('balance_date', '2026-01-02')->sole()->invested_amount)->toBeNull();
});

it('requires authentication', function () {
    $account = Account::factory()->create(['type' => AccountType::Checking]);

    $this->postJson(bulkBalancesUrl($account), [
        'balances' => [['balance_date' => '2026-01-01', 'balance' => 1000]],
    ])->assertUnauthorized();
});

it('refuses another user account without writing anything', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['type' => AccountType::Checking]);

    actingAs($user)->postJson(bulkBalancesUrl($account), [
        'balances' => [['balance_date' => '2026-01-01', 'balance' => 1000]],
    ])->assertForbidden();

    expect(AccountBalance::query()->count())->toBe(0);
});

it('refuses a credit card, which has no balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->creditCard()->create();

    actingAs($user)->postJson(bulkBalancesUrl($account), [
        'balances' => [['balance_date' => '2026-01-01', 'balance' => 1000]],
    ])->assertUnprocessable()->assertJsonValidationErrors('balances');

    expect(AccountBalance::query()->count())->toBe(0);
});

it('refuses a connected account, whose next sync would overwrite the balances', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->connected()->create(['type' => AccountType::Checking]);

    actingAs($user)->postJson(bulkBalancesUrl($account), [
        'balances' => [['balance_date' => '2026-01-01', 'balance' => 1000]],
    ])->assertUnprocessable()->assertJsonValidationErrors('balances');

    expect(AccountBalance::query()->count())->toBe(0);
});

it('reads every spelling of a day as that day', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['type' => AccountType::Checking]);

    actingAs($user)->postJson(bulkBalancesUrl($account), [
        'balances' => [
            ['balance_date' => '2026-01-05', 'balance' => 1000],
            ['balance_date' => '2026-01-05T10:00:00', 'balance' => 2000],
        ],
    ])->assertCreated()->assertJson(['written' => 1]);

    expect($account->balances()->sole())
        ->balance->toBe(2000)
        ->balance_date->toDateString()->toBe('2026-01-05');
});

it('takes a rewritten day away from the full import that wrote it', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['type' => AccountType::Checking]);
    $import = Import::factory()->for($user)->create();
    AccountBalance::factory()->for($account)->create([
        'balance_date' => '2026-01-01',
        'balance' => 1,
        'import_id' => $import->id,
    ]);

    actingAs($user)->postJson(bulkBalancesUrl($account), [
        'balances' => [['balance_date' => '2026-01-01', 'balance' => 1000]],
    ])->assertCreated();

    expect($account->balances()->sole()->import_id)->toBeNull();
});

it('validates every row', function (array $balances, string $error) {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['type' => AccountType::Checking]);

    actingAs($user)->postJson(bulkBalancesUrl($account), ['balances' => $balances])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);

    expect(AccountBalance::query()->count())->toBe(0);
})->with([
    'no balances' => [[], 'balances'],
    'missing date' => [[['balance' => 1000]], 'balances.0.balance_date'],
    'out-of-range year' => [[['balance_date' => '262004-08-10', 'balance' => 1000]], 'balances.0.balance_date'],
    'decimal balance' => [[['balance_date' => '2026-01-01', 'balance' => 10.5]], 'balances.0.balance'],
    'non-numeric invested amount' => [[['balance_date' => '2026-01-01', 'balance' => 1000, 'invested_amount' => 'abc']], 'balances.0.invested_amount'],
]);

it('refuses more than the allowed number of balances', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['type' => AccountType::Checking]);

    $balances = array_fill(0, 5001, ['balance_date' => '2026-01-01', 'balance' => 1]);

    actingAs($user)->postJson(bulkBalancesUrl($account), ['balances' => $balances])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('balances');
});

it('writes five thousand days in one request', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['type' => AccountType::Investment]);

    $balances = array_map(fn (int $day): array => [
        'balance_date' => now()->subDays($day)->toDateString(),
        'balance' => $day,
        'invested_amount' => $day,
    ], range(0, 4999));

    actingAs($user)->postJson(bulkBalancesUrl($account), ['balances' => $balances])
        ->assertCreated()
        ->assertJson(['written' => 5000]);

    expect($account->balances()->count())->toBe(5000);
});
